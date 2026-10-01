<?php

namespace App\Payment;

use App\Entity\Client;
use App\Entity\HelloAssoConfig;
use App\Entity\Payment;
use App\Entity\PaymentStatus;
use App\Integration\Cyclos\CyclosClient;
use App\Integration\HelloAsso\HelloAssoClient;
use App\Integration\HelloAsso\HelloAssoFetchedPayment;
use App\Integration\HelloAsso\HelloAssoNotificationPayload;
use App\Message\ProcessPaymentMessage;
use App\Notification\EmailComposer;
use App\Notification\NotificationMailer;
use App\Repository\EmailAliasRepository;
use App\Repository\HelloAssoConfigRepository;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Orchestrates the payment lifecycle for a client: validating an incoming HelloAsso
 * notification, deciding whether to auto-credit Cyclos, and crediting Cyclos on
 * demand (manual credit, or catch-up "credit all"). Ported from PaymentService.java
 * and CyclosService.java's creditAccount(), parameterized per Client.
 */
class PaymentProcessor
{
    public const NUMBER_LATE_HOURS_ACCEPTED = 12;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentRepository $paymentRepository,
        private readonly EmailAliasRepository $emailAliasRepository,
        private readonly HelloAssoConfigRepository $helloAssoConfigRepository,
        private readonly HelloAssoClient $helloAssoClient,
        private readonly CyclosClient $cyclosClient,
        private readonly NotificationMailer $mailer,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Handles a raw HelloAsso webhook payload for a given client. Returns null when
     * the notification was ignored (malformed, "Order" event, wrong form, unknown
     * state...) rather than turned into a Payment.
     *
     * The cheap validation and the Payment insert happen inline; the Cyclos credit
     * attempt (several HelloAsso/Cyclos HTTP calls) is handed to a worker via
     * ProcessPaymentMessage so the webhook responds before HelloAsso times it out.
     * The returned status is therefore always "todo" for a freshly-created payment.
     */
    public function handleWebhookNotification(Client $client, array $rawPayload): ?PaymentProcessingResult
    {
        $parsed = $this->helloAssoClient->parseNotification($rawPayload);
        if ($parsed === null) {
            return null;
        }

        $haConfig = $this->helloAssoConfigRepository->findOneActiveByClientAndFormSlug($client, $parsed->formSlug);
        $setting = $client->getSetting();

        if ($haConfig === null) {
            $this->logger->error('HelloAsso notification: no active config matches form slug {slug} for client {client}', ['slug' => $parsed->formSlug, 'client' => $client->getSlug()]);

            return null;
        }

        $alreadyKnown = $this->paymentRepository->findOneByClientAndHelloAssoId($client, $parsed->helloAssoPaymentId) !== null;

        if ($parsed->amountCents > $haConfig->getMaxAmount() * 100) {
            if (!$alreadyKnown) {
                $payment = $this->persist($this->buildPayment($client, $haConfig, $parsed), PaymentStatus::TooHigh);
                if ($payment !== null) {
                    $this->notifyOverLimit($client, $payment);
                }
            }

            return null;
        }

        if (!\in_array($parsed->state, ['Authorized', 'Waiting'], true)) {
            $this->logger->error('HelloAsso notification: unexpected state {state}', ['state' => $parsed->state]);

            return null;
        }

        if ($parsed->rawDate === '') {
            $this->logger->error('HelloAsso notification: missing date');

            return null;
        }

        if ($alreadyKnown) {
            $this->logger->debug('Payment {id} already known for client {client}', ['id' => $parsed->helloAssoPaymentId, 'client' => $client->getSlug()]);

            return null;
        }

        $payment = $this->persist($this->buildPayment($client, $haConfig, $parsed), PaymentStatus::Todo);
        if ($payment === null) {
            return null;
        }

        if ($setting->isPaymentAutomaticEnabled()) {
            $this->messageBus->dispatch(new ProcessPaymentMessage($payment->getId(), $parsed->state === 'Waiting'));
        }

        return new PaymentProcessingResult(PaymentStatus::Todo);
    }

    /**
     * Worker entry point for ProcessPaymentMessage: runs the automatic-credit
     * decision for a payment the webhook left as "todo". Idempotent — a redelivered
     * message finds the payment in a non-"todo" status and does nothing, so it never
     * re-credits or re-notifies.
     */
    public function processQueuedPayment(int $paymentId, bool $reportedWaiting): void
    {
        $payment = $this->paymentRepository->find($paymentId);

        if ($payment === null) {
            $this->logger->warning('ProcessPaymentMessage: payment {id} no longer exists, skipping', ['id' => $paymentId]);

            return;
        }

        if ($payment->getStatus() !== PaymentStatus::Todo) {
            $this->logger->debug('ProcessPaymentMessage: payment {id} already in status {status}, skipping', ['id' => $paymentId, 'status' => $payment->getStatus()->value]);

            return;
        }

        $this->applyAutomaticDecision($payment->getClient(), $payment, $reportedWaiting);
    }

    /**
     * Catch-up fetch: pulls the client's recent HelloAsso payment history and saves
     * any payment not already known. With $attemptAutomaticCredit = true (both the
     * scheduled `app:helloasso:fetch` and the manual "Synchro Hello Asso" button)
     * each newly-discovered payment runs through the same automatic-credit decision
     * as a real-time webhook, so it is credited straight away when the client
     * setting and the accepted delay allow it. Pass false for a record-only sweep
     * that must never move money: every discovered payment is then left in "todo"
     * for manual review.
     */
    public function fetchMissingPayments(Client $client, bool $attemptAutomaticCredit = false): int
    {
        $known = array_flip($this->paymentRepository->findAllHelloAssoIdsForClient($client));
        $added = 0;

        foreach ($client->getActiveHelloAssoConfigs() as $haConfig) {
            $fetched = $this->helloAssoClient->fetchPaymentsHistory($haConfig, $haConfig->getFetchNbDays());

            foreach ($fetched as $item) {
                if (isset($known[$item->helloAssoPaymentId])) {
                    continue;
                }

                if ($attemptAutomaticCredit) {
                    $this->ingestFetchedPayment($client, $haConfig, $item);
                } else {
                    // Safety-net path: record as "todo" (the Payment default status),
                    // batch-flushed once below.
                    $this->entityManager->persist($this->buildPayment($client, $haConfig, $item));
                }

                $known[$item->helloAssoPaymentId] = true;
                $added++;
            }
        }

        if ($added > 0) {
            try {
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // A concurrent fetch/webhook inserted one of these first. The batch
                // is lost, but the next scheduled fetch re-discovers anything still
                // missing — this path is a safety net, not the source of truth.
                $this->logger->warning('Catch-up fetch for client {slug}: concurrent insert, batch rolled back — next run will retry', ['slug' => $client->getSlug()]);

                return 0;
            }
        }

        return $added;
    }

    /**
     * Mirrors the webhook's "amount over the limit" gate, then runs the shared
     * automatic-credit decision — used only when a manual sync should behave like
     * a real-time notification for each payment it discovers.
     */
    private function ingestFetchedPayment(Client $client, HelloAssoConfig $haConfig, HelloAssoFetchedPayment $item): void
    {
        if ($item->amountCents > $haConfig->getMaxAmount() * 100) {
            $payment = $this->persist($this->buildPayment($client, $haConfig, $item), PaymentStatus::TooHigh);
            if ($payment !== null) {
                $this->notifyOverLimit($client, $payment);
            }

            return;
        }

        $payment = $this->persist($this->buildPayment($client, $haConfig, $item), PaymentStatus::Todo);
        if ($payment === null) {
            return;
        }

        $this->applyAutomaticDecision($client, $payment, reportedWaiting: false);
    }

    /**
     * Shared automatic-credit decision applied to a freshly-persisted "todo"
     * payment: bails out if automatic crediting is disabled for the client,
     * marks late/still-waiting payments without attempting a credit, otherwise
     * attempts the Cyclos credit and sends the success/failure notification.
     */
    private function applyAutomaticDecision(Client $client, Payment $payment, bool $reportedWaiting): PaymentProcessingResult
    {
        $setting = $client->getSetting();

        if (!$setting->isPaymentAutomaticEnabled()) {
            return new PaymentProcessingResult(PaymentStatus::Todo);
        }

        if ($this->isLate($payment->getPaymentDate())) {
            $payment->setStatus(PaymentStatus::TooLate);
            $this->entityManager->flush();
            $this->mailer->sendForClient($client, $setting->getMailRecipient(), 'too_late', $this->emailParams($client, $payment));

            return new PaymentProcessingResult(PaymentStatus::TooLate);
        }

        if ($reportedWaiting) {
            $payment->setStatus(PaymentStatus::Waiting);
            $this->entityManager->flush();
            $this->mailer->sendForClient($client, $setting->getMailRecipient(), 'waiting', $this->emailParams($client, $payment));

            return new PaymentProcessingResult(PaymentStatus::Waiting);
        }

        $result = $this->creditCyclosAccount($client, $payment);

        if ($result->status === PaymentStatus::Success) {
            $payment->setStatus(PaymentStatus::SuccessAuto);
            $this->entityManager->flush();

            if ($setting->isNotifySuccessOnPayment() && $client->getContactEmail() !== null) {
                $this->mailer->sendForClient($client, $client->getContactEmail(), 'success', $this->emailParams($client, $payment));
            }

            return new PaymentProcessingResult(PaymentStatus::SuccessAuto, $result->errors);
        }

        if ($setting->isNotifyFailureOnPayment() && $client->getContactEmail() !== null) {
            $this->mailer->sendForClient($client, $client->getContactEmail(), 'failure', $this->emailParams($client, $payment));
        }

        return $result;
    }

    /**
     * Manually (re)credits a payment to Cyclos: used by the "credit" button and the
     * catch-up "credit all" command. Sends a technical error email on failure.
     */
    public function creditManually(Payment $payment): PaymentProcessingResult
    {
        $client = $payment->getClient();
        $result = $this->creditCyclosAccount($client, $payment);

        if (!$result->isSuccessful()) {
            $this->mailer->sendForClient(
                $client,
                $client->getSetting()->getMailRecipient(),
                'manual_error',
                $this->emailParams($client, $payment) + ['%errors%' => implode("\n", $result->errors)],
            );
        }

        return $result;
    }

    /**
     * Marks a payment as funded manually outside Cyllos (e.g. credited by hand
     * directly in Cyclos for technical reasons) instead of crediting it via the
     * Cyclos API. Idempotent in the same sense as creditCyclosAccount(): a
     * payment already credited (by any means) is left untouched.
     */
    public function markAsManuallyFunded(Payment $payment): PaymentProcessingResult
    {
        if ($payment->getStatus()->isSuccessful()) {
            return new PaymentProcessingResult($payment->getStatus(), ['Paiement déjà effectué dans Cyclos']);
        }

        $payment->setStatus(PaymentStatus::ManualCredit);
        $payment->setError(null);
        $this->entityManager->flush();

        return new PaymentProcessingResult(PaymentStatus::ManualCredit);
    }

    private function creditCyclosAccount(Client $client, Payment $payment): PaymentProcessingResult
    {
        if ($payment->getStatus()->isSuccessful()) {
            $this->logger->info('Payment {id} already credited', ['id' => $payment->getHelloAssoPaymentId()]);

            return new PaymentProcessingResult($payment->getStatus(), ['Paiement déjà effectué dans Cyclos']);
        }

        $cyclosConfig = $client->getCyclosConfig();
        $haConfig = $payment->getHelloAssoConfig();
        $setting = $client->getSetting();

        $email = $payment->getEmail();

        $alias = $this->emailAliasRepository->findOneByClientAndSourceEmail($client, $payment->getPayerEmail());
        if ($alias !== null) {
            $email = $alias->getTargetEmail();
        }

        $user = $this->cyclosClient->findUserByEmail($cyclosConfig, $email);

        if ($user === null) {
            $alternativeEmail = $this->helloAssoClient->getAlternativeEmail($haConfig, $payment->getHelloAssoPaymentId());

            if ($alternativeEmail === null || !str_contains($alternativeEmail, '@')) {
                return $this->fail($payment, "Erreur pendant la récupération de l'utilisateur dans Cyclos");
            }

            $user = $this->cyclosClient->findUserByEmail($cyclosConfig, $alternativeEmail);
            if ($user === null) {
                return $this->fail($payment, "Erreur pendant la récupération de l'utilisateur dans Cyclos (email alternatif)");
            }

            $email = $alternativeEmail;
        }

        $emissionType = $this->cyclosClient->resolveEmissionType($cyclosConfig, $user);
        if ($emissionType === null) {
            return $this->fail($payment, \sprintf("Le groupe Cyclos de l'utilisateur (%s) n'est pas autorisé à recevoir des paiements automatiques", $user->groupInternalName));
        }

        $customPrefix = $client->getCustomization()?->getCyclosDescriptionPrefix();
        $prefix = ($customPrefix !== null && $customPrefix !== '')
            ? $customPrefix
            : CyclosClient::PAYMENT_DESCRIPTION_PREFIX;
        $description = $prefix . $payment->getHelloAssoPaymentId();

        // Also look for the default/legacy wording: a client that switched its
        // description prefix must not get a payment credited twice because the
        // earlier credit is recorded in Cyclos under the old description.
        $duplicateCandidates = array_values(array_unique([
            $description,
            CyclosClient::PAYMENT_DESCRIPTION_PREFIX . $payment->getHelloAssoPaymentId(),
        ]));

        if ($this->cyclosClient->hasAlreadyCreditedPayment($cyclosConfig, $email, $duplicateCandidates)) {
            return $this->fail($payment, 'Paiement déjà réalisé dans Cyclos');
        }

        $preview = !$setting->isPaymentCyclosEnabled();
        $paymentResult = $this->cyclosClient->performPayment($cyclosConfig, $email, $payment->getAmount(), $description, $emissionType, $preview);

        if (!$paymentResult->success) {
            return $this->fail($payment, $paymentResult->errorMessage ?? 'Erreur inconnue lors du paiement Cyclos');
        }

        $status = $preview ? PaymentStatus::PreviewOk : PaymentStatus::Success;
        $payment->setStatus($status);
        $payment->setEmail($email);
        $payment->setError(null);
        $this->entityManager->flush();

        return new PaymentProcessingResult($status);
    }

    private function fail(Payment $payment, string $error): PaymentProcessingResult
    {
        $payment->setStatus(PaymentStatus::Fail);
        $payment->setError($error);
        $this->entityManager->flush();

        return new PaymentProcessingResult(PaymentStatus::Fail, [$error]);
    }

    /**
     * Builds an unpersisted Payment from either kind of HelloAsso payload — the
     * webhook notification and the fetched-history item carry the same fields for
     * this purpose. Does not set a status or flush; see persist().
     */
    private function buildPayment(Client $client, HelloAssoConfig $haConfig, HelloAssoNotificationPayload|HelloAssoFetchedPayment $data): Payment
    {
        return new Payment(
            client: $client,
            helloAssoConfig: $haConfig,
            helloAssoPaymentId: $data->helloAssoPaymentId,
            paymentDate: $this->parseDate($data->rawDate),
            amount: $data->amountCents / 100,
            payerFirstName: $data->payerFirstName,
            payerLastName: $data->payerLastName,
            email: $data->payerEmail,
        );
    }

    /**
     * Persists a freshly-built Payment. Returns null when a concurrent request
     * already inserted the same (client, helloAssoPaymentId) — the unique index
     * turns the race into a caught "ignore" rather than an uncaught 500 that makes
     * HelloAsso retry. The EntityManager is closed by the exception, so a null
     * return means the caller must not touch the ORM afterwards.
     */
    private function persist(Payment $payment, PaymentStatus $status): ?Payment
    {
        $payment->setStatus($status);
        $this->entityManager->persist($payment);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->logger->debug('Payment {id} was inserted concurrently, ignoring the duplicate', ['id' => $payment->getHelloAssoPaymentId()]);

            return null;
        }

        return $payment;
    }

    private function notifyOverLimit(Client $client, Payment $payment): void
    {
        $this->mailer->sendForClient(
            $client,
            $client->getSetting()->getMailRecipient(),
            'over_limit',
            $this->emailParams($client, $payment),
        );
    }

    /**
     * The %placeholder% values shared by every payment notification. Callers
     * merge in any type-specific extra (e.g. '%errors%' for manual_error).
     * A placeholder a given template doesn't use is simply ignored.
     *
     * @return array<string, string|int>
     */
    private function emailParams(Client $client, Payment $payment): array
    {
        $mode = $client->getSetting()->isPaymentCyclosEnabled()
            ? EmailComposer::REAL_MODE_LABEL
            : ($client->getCustomization()?->getPreviewModeLabel() ?? EmailComposer::DEFAULT_PREVIEW_MODE_LABEL);

        return [
            '%id%' => $payment->getHelloAssoPaymentId(),
            '%amount%' => \sprintf('%.2f', $payment->getAmount()),
            '%payer%' => trim($payment->getPayerFirstName() . ' ' . $payment->getPayerLastName()),
            '%payer_email%' => $payment->getPayerEmail(),
            '%form%' => $payment->getHelloAssoConfig()->getLabel(),
            '%date%' => $payment->getPaymentDate()->format('d/m/Y'),
            '%client%' => $client->getName(),
            '%mode%' => $mode,
        ];
    }

    /**
     * Parses a HelloAsso payment date. An unparseable date must never be silently
     * replaced by "now": that would make a genuinely old payment look fresh and let
     * it slip through automatic crediting. Instead we fall back to the Unix epoch,
     * so isLate() flags the payment and it is routed to manual review.
     */
    private function parseDate(string $rawDate): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($rawDate);
        } catch (\Exception) {
            $this->logger->warning('HelloAsso payment date could not be parsed ({raw}); treating the payment as late for manual review', ['raw' => $rawDate]);

            return new \DateTimeImmutable('@0');
        }
    }

    private function isLate(\DateTimeImmutable $paymentDate): bool
    {
        $deadline = $paymentDate->modify(\sprintf('+%d hours', self::NUMBER_LATE_HOURS_ACCEPTED));

        return new \DateTimeImmutable() > $deadline;
    }
}
