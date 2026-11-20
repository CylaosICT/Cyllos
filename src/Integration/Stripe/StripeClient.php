<?php

namespace App\Integration\Stripe;

use App\ActivityLog\ApiCallLogger;
use App\Entity\CheckoutOrder;
use App\Entity\StripeConfig;
use App\Security\SecretEncryptor;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Creates Stripe Checkout Sessions (hosted full page) on a client's own
 * Stripe account, authenticated with that client's stored API key — the money
 * goes straight to the client's account, never through Cylaos.
 */
class StripeClient
{
    public const API_BASE_URL = 'https://api.stripe.com';

    /**
     * Pinned so Stripe never changes request/response shapes under us; bump
     * deliberately after re-reading Stripe's changelog. `ui_mode=hosted_page`
     * (the redirect-to-Stripe full page) is the value of this API version.
     */
    public const API_VERSION = '2026-10-28.endive';

    /** Stripe requires at least 30 minutes between creation and expiry. */
    private const SESSION_LIFETIME_SECONDS = 1860;

    private const REQUEST_TIMEOUT = 30.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretEncryptor $secretEncryptor,
        private readonly LoggerInterface $logger,
        private readonly ApiCallLogger $apiCallLogger,
    ) {
    }

    /**
     * Opens a Checkout Session for an order. The order reference is both the
     * Idempotency-Key (so a retried request never creates a second session)
     * and the session's client_reference_id / metadata, which is how the
     * webhook ties the confirmed payment back to the order.
     *
     * Card only, on purpose: Cyclos is credited as soon as Stripe confirms the
     * payment, which only makes sense for instant, synchronous methods.
     *
     * @throws StripeException
     */
    public function createCheckoutSession(
        StripeConfig $config,
        CheckoutOrder $order,
        string $productName,
        string $successUrl,
        string $cancelUrl,
    ): StripeCheckoutSession {
        $params = [
            'mode' => 'payment',
            'ui_mode' => 'hosted_page',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $order->getEmail(),
            'client_reference_id' => $order->getReference(),
            'expires_at' => time() + self::SESSION_LIFETIME_SECONDS,
            'metadata' => ['order_reference' => $order->getReference()],
            'payment_intent_data' => [
                'description' => $productName . ' — ' . $order->getReference(),
                'metadata' => ['order_reference' => $order->getReference()],
            ],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => $order->getAmountCents(),
                    'product_data' => ['name' => $productName],
                ],
            ]],
        ];

        $url = self::API_BASE_URL . '/v1/checkout/sessions';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'auth_bearer' => $this->secretEncryptor->decrypt($config->getSecretKeyEncrypted()),
                'headers' => [
                    'Stripe-Version' => self::API_VERSION,
                    'Idempotency-Key' => $order->getReference(),
                ],
                'body' => $params,
                'timeout' => self::REQUEST_TIMEOUT,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->error('Stripe: could not reach the API: {message}', ['message' => $e->getMessage()]);

            throw new StripeException('Stripe est injoignable.', previous: $e);
        }

        // The API key is never part of $params, so the request body is safe to trace.
        $this->apiCallLogger->record('stripe', 'POST', $url, http_build_query($params), $status, $content, 'POST /v1/checkout/sessions', clientId: $config->getClient()?->getId());

        $data = json_decode($content, true);
        if ($status >= 300 || !\is_array($data)) {
            $message = \is_array($data) ? ($data['error']['message'] ?? 'erreur inconnue') : 'réponse illisible';
            $this->logger->error('Stripe: Checkout Session creation failed ({status}): {message}', ['status' => $status, 'message' => $message]);

            throw new StripeException(\sprintf('Stripe a refusé la création du paiement (%s).', $message));
        }

        if (!isset($data['id'], $data['url']) || !\is_string($data['id']) || !\is_string($data['url'])) {
            throw new StripeException('Réponse Stripe inattendue : session sans identifiant ou sans URL.');
        }

        return new StripeCheckoutSession($data['id'], $data['url']);
    }
}
