<?php

namespace App\Controller;

use App\Entity\CheckoutOrderStatus;
use App\Integration\Stripe\StripeWebhookVerifier;
use App\Payment\StripePaymentIngestor;
use App\Repository\ClientRepository;
use App\Security\SecretEncryptor;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives the events of a client's Stripe account. Unlike HelloAsso, Stripe
 * signs every delivery: the Stripe-Signature header is verified against the
 * client's own webhook secret, and nothing is read from the body before that.
 */
class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly StripePaymentIngestor $ingestor,
        private readonly StripeWebhookVerifier $verifier,
        private readonly SecretEncryptor $secretEncryptor,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $webhookLimiter,
    ) {
    }

    #[Route(path: '/webhook/stripe/{clientSlug}', name: 'webhook_stripe', methods: ['POST'], requirements: ['clientSlug' => '[a-z0-9-]+'])]
    public function receive(string $clientSlug, Request $request): Response
    {
        if (!$this->webhookLimiter->create('stripe-' . $clientSlug)->consume()->isAccepted()) {
            $this->logger->warning('Stripe webhook rate limit hit for client "{slug}"', ['slug' => $clientSlug]);

            return new Response(status: Response::HTTP_TOO_MANY_REQUESTS);
        }

        $client = $this->clientRepository->findOneBySlug($clientSlug);
        $config = $client?->getStripeConfig();

        if ($client === null || !$client->isActive() || !$client->usesStripe() || $config === null || !$config->isActive()) {
            $this->logger->warning('Stripe webhook received for unknown, inactive or non-Stripe client "{slug}"', ['slug' => $clientSlug]);

            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        $payload = $request->getContent();
        $secret = $this->secretEncryptor->decrypt($config->getWebhookSecretEncrypted());

        if (!$this->verifier->verify($payload, $request->headers->get('Stripe-Signature'), $secret)) {
            $this->logger->warning('Stripe webhook with an invalid signature for client "{slug}"', ['slug' => $clientSlug]);

            return new Response(status: Response::HTTP_BAD_REQUEST);
        }

        $event = json_decode($payload, true);
        if (!\is_array($event) || !isset($event['type']) || !\is_string($event['type']) || !\is_array($event['data']['object'] ?? null)) {
            return new Response(status: Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $object */
        $object = $event['data']['object'];
        $eventTime = new \DateTimeImmutable('@' . (\is_int($event['created'] ?? null) ? $event['created'] : time()));

        $this->logger->info('Stripe webhook {type} received for client "{slug}"', ['type' => $event['type'], 'slug' => $clientSlug]);

        $outcome = match ($event['type']) {
            // "paid" = money confirmed. With card only this is the normal case;
            // "unpaid" (a delayed method) is settled later by async_payment_succeeded.
            'checkout.session.completed' => ($object['payment_status'] ?? null) === 'paid'
                ? $this->recordPaid($client, $object, $eventTime)
                : 'awaiting_payment',
            'checkout.session.async_payment_succeeded' => $this->recordPaid($client, $object, $eventTime),
            'checkout.session.async_payment_failed' => $this->close($client, $object, CheckoutOrderStatus::Failed),
            'checkout.session.expired' => $this->close($client, $object, CheckoutOrderStatus::Expired),
            // Money coming back after the Cyclos credit cannot be undone
            // automatically: make it loud in the logs for a human to handle.
            'charge.refunded', 'charge.dispute.created' => $this->flagReversal($clientSlug, $event['type'], $object),
            default => 'ignored',
        };

        return new JsonResponse(['status' => $outcome]);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function recordPaid(\App\Entity\Client $client, array $session, \DateTimeImmutable $eventTime): string
    {
        return $this->ingestor->ingestPaidSession($client, $session, $eventTime) !== null ? 'recorded' : 'ignored';
    }

    /**
     * @param array<string, mixed> $session
     */
    private function close(\App\Entity\Client $client, array $session, CheckoutOrderStatus $status): string
    {
        $this->ingestor->closeOrder($client, $session, $status);

        return $status->value;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function flagReversal(string $clientSlug, string $type, array $object): string
    {
        $this->logger->critical('Stripe {type} for client "{slug}" (payment intent {intent}): the payer was already credited in Cyclos — manual follow-up required', [
            'type' => $type,
            'slug' => $clientSlug,
            'intent' => \is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : 'n/a',
        ]);

        return 'flagged';
    }
}
