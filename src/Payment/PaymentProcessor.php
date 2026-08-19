<?php

namespace App\Payment;

use App\Entity\Client;
use App\Entity\Payment;
use App\Entity\PaymentStatus;
use App\Integration\Cyclos\CyclosClient;
use App\Integration\HelloAsso\HelloAssoClient;
use App\Integration\HelloAsso\HelloAssoFetchedPayment;
use App\Integration\HelloAsso\HelloAssoNotificationPayload;
use App\Notification\NotificationMailer;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

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
        private readonly HelloAssoClient $helloAssoClient,
        private readonly CyclosClient $cyclosClient,
        private readonly NotificationMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handles a raw HelloAsso webhook payload for a given client. Returns null when
     * the notification was ignored (malformed, "Order" event, wrong form, unknown
     * state...) rather than turned into a Payment.
     */
    public function handleWebhookNotification(Client $client, array $rawPayload): ?PaymentProcessingResult
    {
        $parsed = $this->helloAssoClient->parseNotification($rawPayload);
        if ($parsed === null) {
            return null;
        }

        $haConfig = $client->getHelloAssoConfig();
        $setting = $client->getSetting();

        if ($parsed->formSlug !== $haConfig->getFormSlug()) {
            $this->logger->error('HelloAsso notification: form slug mismatch for client {client}', ['client' => $client->getSlug()]);

            return null;
        }

        $alreadyKnown = $this->paymentRepository->findOneByClientAndHelloAssoId($client, $parsed->helloAssoPaymentId) !== null;

        if ($parsed->amountCents > $haConfig->getMaxAmount() * 100) {
            if (!$alreadyKnown) {
                $payment = $this->persistNewPayment($client, $parsed, PaymentStatus::TooHigh);
                $this->mailer->send(
                    $setting->getMailRecipient(),
                    '[Cyllos] Paiement dépassant la limite',
                    sprintf("Un paiement a dépassé la limite autorisée, approbation manuelle requise.\nId : %d\nMontant : %.2f €", $payment->getHelloAssoPaymentId(), $payment->getAmount()),
                );
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

        $payment = $this->persistNewPayment($client, $parsed, PaymentStatus::Todo);

        return $this->applyAutomaticDecision($client, $payment, $parsed->state === 'Waiting');
    }

    /**
     * Catch-up fetch: pulls the client's recent HelloAsso payment history and saves
     * any payment not already known. Set $attemptAutomaticCredit to true to run each
     * newly-discovered payment through the same automatic-credit decision as the
     * real-time webhook (used by the manual "Synchro Hello Asso" button); left false
     * for the periodic safety-net command, which only records missed payments as
     * "todo" for manual review.
     */
    public function fetchMissingPayments(Client $client, bool $attemptAutomaticCredit = false): int
    {
        $haConfig = $client->getHelloAssoConfig();
        $known = array_flip($this->paymentRepository->findAllHelloAssoIdsForClient($client));

        $fetched = $this->helloAssoClient->fetchPaymentsHistory($haConfig, $haConfig->getFetchNbDays());
        $added = 0;

        foreach ($fetched as $item) {
            if (isset($known[$item->helloAssoPaymentId])) {
                continue;
            }

            if ($attemptAutomaticCredit) {
                $this->ingestFetchedPayment($client, $item);
            } else {
                $payment = new Payment(
                    client: $client,
                    helloAssoPaymentId: $item->helloAssoPaymentId,
                    paymentDate: $this->parseDate($item->rawDate),
                    amount: $item->amountCents / 100,
                    payerFirstName: $item->payerFirstName,
                    payerLastName: $item->payerLastName,
                    email: $item->payerEmail,
                );
                $this->entityManager->persist($payment);
            }

            $known[$item->helloAssoPaymentId] = true;
            $added++;
        }

        if ($added > 0) {
            $this->entityManager->flush();
        }

        return $added;
    }

    /**
     * Mirrors the webhook's "amount over the limit" gate, then runs the shared
     * automatic-credit decision — used only when a manual sync should behave like
     * a real-time notification for each payment it discovers.
     */
    private function ingestFetchedPayment(Client $client, HelloAssoFetchedPayment $item): void
    {
        $haConfig = $client->getHelloAssoConfig();
        $setting = $client->getSetting();

        if ($item->amountCents > $haConfig->getMaxAmount() * 100) {
            $payment = $this->persistFetchedPayment($client, $item, PaymentStatus::TooHigh);
            $this->mailer->send(
                $setting->getMailRecipient(),
                '[Cyllos] Paiement dépassant la limite',
                sprintf("Un paiement a dépassé la limite autorisée, approbation manuelle requise.\nId : %d\nMontant : %.2f €", $payment->getHelloAssoPaymentId(), $payment->getAmount()),
            );

            return;
        }

        $payment = $this->persistFetchedPayment($client, $item, PaymentStatus::Todo);
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
            $this->mailer->send(
                $setting->getMailRecipient(),
                '[Cyllos] Paiement en retard',
                sprintf('Un paiement a été reçu en retard.\nId : %d', $payment->getHelloAssoPaymentId()),
            );

            return new PaymentProcessingResult(PaymentStatus::TooLate);
        }

        if ($reportedWaiting) {
            $payment->setStatus(PaymentStatus::Waiting);
            $this->entityManager->flush();
            $this->mailer->send(
                $setting->getMailRecipient(),
                '[Cyllos] Paiement en attente',
                sprintf("Un paiement a été reçu avec l'état 'Attente'.\nId : %d", $payment->getHelloAssoPaymentId()),
            );

            return new PaymentProcessingResult(PaymentStatus::Waiting);
        }

        $result = $this->creditCyclosAccount($client, $payment);

        if ($result->status === PaymentStatus::Success) {
            $payment->setStatus(PaymentStatus::SuccessAuto);
            $this->entityManager->flush();

            if ($setting->isNotifySuccessOnPayment() && $client->getContactEmail() !== null) {
                $this->mailer->send(
                    $client->getContactEmail(),
                    '[Cyllos] Paiement réussi :)',
                    sprintf('Un paiement a été effectué avec succès.\nId : %d\nMontant : %.2f €', $payment->getHelloAssoPaymentId(), $payment->getAmount()),
                );
            }

            return new PaymentProcessingResult(PaymentStatus::SuccessAuto, $result->errors);
        }

        if ($setting->isNotifyFailureOnPayment() && $client->getContactEmail() !== null) {
            $this->mailer->send(
                $client->getContactEmail(),
                '[Cyllos] Paiement en échec :(',
                sprintf("Un paiement n'a pas pu être effectué.\nId : %d\nMontant : %.2f €", $payment->getHelloAssoPaymentId(), $payment->getAmount()),
            );
        }

        return $result;
    }

    /**
     * Manually (re)credits a payment to Cyclos: used by the "credit" button and the
     * catch-up "credit all" command. Sends a technical error email on failure.
     */
    public function creditManually(Payment $payment): PaymentProcessingResult
    {
        $result = $this->creditCyclosAccount($payment->getClient(), $payment);

        if (!$result->isSuccessful()) {
            $this->mailer->send(
                $payment->getClient()->getSetting()->getMailRecipient(),
                '[Cyllos] Erreur lors du traitement',
                sprintf("Liste des erreurs pour le paiement %d :\n%s", $payment->getHelloAssoPaymentId(), implode("\n", $result->errors)),
            );
        }

        return $result;
    }

    private function creditCyclosAccount(Client $client, Payment $payment): PaymentProcessingResult
    {
        if ($payment->getStatus()->isSuccessful()) {
            $this->logger->info('Payment {id} already credited', ['id' => $payment->getHelloAssoPaymentId()]);

            return new PaymentProcessingResult($payment->getStatus(), ['Paiement déjà effectué dans Cyclos']);
        }

        $cyclosConfig = $client->getCyclosConfig();
        $haConfig = $client->getHelloAssoConfig();
        $setting = $client->getSetting();

        $email = $payment->getEmail();
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
            return $this->fail($payment, sprintf("Le groupe Cyclos de l'utilisateur (%s) n'est pas autorisé à recevoir des paiements automatiques", $user->groupInternalName));
        }

        $description = CyclosClient::PAYMENT_DESCRIPTION_PREFIX . $payment->getHelloAssoPaymentId();

        if ($this->cyclosClient->hasAlreadyCreditedPayment($cyclosConfig, $email, $description)) {
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

    private function persistNewPayment(Client $client, HelloAssoNotificationPayload $parsed, PaymentStatus $status): Payment
    {
        $payment = new Payment(
            client: $client,
            helloAssoPaymentId: $parsed->helloAssoPaymentId,
            paymentDate: $this->parseDate($parsed->rawDate),
            amount: $parsed->amountCents / 100,
            payerFirstName: $parsed->payerFirstName,
            payerLastName: $parsed->payerLastName,
            email: $parsed->payerEmail,
        );
        $payment->setStatus($status);

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        return $payment;
    }

    private function persistFetchedPayment(Client $client, HelloAssoFetchedPayment $item, PaymentStatus $status): Payment
    {
        $payment = new Payment(
            client: $client,
            helloAssoPaymentId: $item->helloAssoPaymentId,
            paymentDate: $this->parseDate($item->rawDate),
            amount: $item->amountCents / 100,
            payerFirstName: $item->payerFirstName,
            payerLastName: $item->payerLastName,
            email: $item->payerEmail,
        );
        $payment->setStatus($status);

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        return $payment;
    }

    private function parseDate(string $rawDate): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($rawDate);
        } catch (\Exception) {
            return new \DateTimeImmutable();
        }
    }

    private function isLate(\DateTimeImmutable $paymentDate): bool
    {
        $deadline = $paymentDate->modify(sprintf('+%d hours', self::NUMBER_LATE_HOURS_ACCEPTED));

        return new \DateTimeImmutable() > $deadline;
    }
}
