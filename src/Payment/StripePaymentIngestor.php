<?php

namespace App\Payment;

use App\Entity\CheckoutOrder;
use App\Entity\CheckoutOrderStatus;
use App\Entity\Client;
use App\Entity\Payment;
use App\Entity\PaymentStatus;
use App\Message\ProcessPaymentMessage;
use App\Repository\CheckoutOrderRepository;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Turns Stripe's "this Checkout Session is paid" confirmation into a Payment —
 * the Stripe counterpart of PaymentProcessor::handleWebhookNotification().
 * Like it, only records the payment (status "todo") and, when the client has
 * automatic crediting on, hands the Cyclos credit to a worker through
 * ProcessPaymentMessage; the decision logic itself (lateness, Cyclos lookup,
 * anti-duplicate, notifications) is the shared PaymentProcessor one.
 */
class StripePaymentIngestor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CheckoutOrderRepository $orderRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $session the Checkout Session object from the webhook event
     *
     * @return Payment|null the newly recorded payment, or null when ignored (unknown order,
     *                      already recorded, not really paid...)
     */
    public function ingestPaidSession(Client $client, array $session, \DateTimeImmutable $eventTime): ?Payment
    {
        $order = $this->findOrder($client, $session);
        if ($order === null) {
            return null;
        }

        if ($this->paymentRepository->findOneByClientAndReference($client, $order->getReference()) !== null) {
            $this->logger->debug('Stripe order {ref} already recorded for client {client}', ['ref' => $order->getReference(), 'client' => $client->getSlug()]);

            return null;
        }

        $paymentIntent = isset($session['payment_intent']) && \is_string($session['payment_intent']) ? $session['payment_intent'] : null;
        $order->markPaid($paymentIntent, $eventTime);

        $payerName = $session['customer_details']['name'] ?? '';
        $payment = Payment::forStripeOrder($order, $eventTime, \is_string($payerName) ? $payerName : '');

        $paidCents = $session['amount_total'] ?? null;
        $currency = isset($session['currency']) && \is_string($session['currency']) ? strtolower($session['currency']) : '';
        if ($paidCents !== $order->getAmountCents() || $currency !== 'eur') {
            // Money was taken but not for what we asked: never auto-credit a
            // different amount. Record it as failed so it is visible and can be
            // dealt with by hand.
            $this->logger->error('Stripe order {ref}: paid {paid} {currency}, expected {expected} cents EUR', [
                'ref' => $order->getReference(),
                'paid' => \is_scalar($paidCents) ? (string) $paidCents : 'n/a',
                'currency' => $currency,
                'expected' => $order->getAmountCents(),
            ]);
            $payment->setStatus(PaymentStatus::Fail);
            $payment->setError('Montant ou devise reçus de Stripe différents de la commande : vérifier dans Stripe avant tout crédit.');

            return $this->persist($payment);
        }

        $payment = $this->persist($payment);
        if ($payment === null) {
            return null;
        }

        if ($client->getSetting()->isPaymentAutomaticEnabled()) {
            $this->messageBus->dispatch(new ProcessPaymentMessage($payment->getId(), false));
        }

        return $payment;
    }

    /**
     * The order a session was created for, resolved from the reference we put
     * in it. The session id must also match, so a forged-but-signed event for
     * another session of the same account can't settle someone else's order.
     *
     * @param array<string, mixed> $session
     */
    public function findOrder(Client $client, array $session): ?CheckoutOrder
    {
        $reference = $session['client_reference_id'] ?? ($session['metadata']['order_reference'] ?? null);
        if (!\is_string($reference) || $reference === '') {
            $this->logger->warning('Stripe webhook for client {client}: session without an order reference, ignored', ['client' => $client->getSlug()]);

            return null;
        }

        $order = $this->orderRepository->findOneByClientAndReference($client, $reference);
        if ($order === null) {
            $this->logger->warning('Stripe webhook for client {client}: unknown order {ref}', ['client' => $client->getSlug(), 'ref' => $reference]);

            return null;
        }

        $sessionId = $session['id'] ?? null;
        if (!\is_string($sessionId) || $order->getStripeSessionId() !== $sessionId) {
            $this->logger->error('Stripe webhook for client {client}: order {ref} does not match session', ['client' => $client->getSlug(), 'ref' => $reference]);

            return null;
        }

        return $order;
    }

    /**
     * Marks a still-pending order as expired/failed (the payer never paid).
     * A paid order is never downgraded.
     *
     * @param array<string, mixed> $session
     */
    public function closeOrder(Client $client, array $session, CheckoutOrderStatus $status): void
    {
        $order = $this->findOrder($client, $session);
        if ($order === null || $order->getStatus() !== CheckoutOrderStatus::Pending) {
            return;
        }

        $order->setStatus($status);
        $this->entityManager->flush();
    }

    private function persist(Payment $payment): ?Payment
    {
        $this->entityManager->persist($payment);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->logger->debug('Stripe payment {ref} was inserted concurrently, ignoring the duplicate', ['ref' => $payment->getReference()]);

            return null;
        }

        return $payment;
    }
}
