<?php

namespace App\Integration\HelloAsso;

use App\Entity\HelloAssoConfig;
use App\Security\SecretEncryptor;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to the HelloAsso API for a given client's configuration.
 *
 * Ported from HelloAssoService.java, parameterized by HelloAssoConfig instead of
 * a single global .env configuration.
 */
class HelloAssoClient
{
    private const REQUEST_TIMEOUT = 60.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretEncryptor $secretEncryptor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Structurally parses a "Payment" webhook body. Returns null for anything that
     * isn't a well-formed payment notification (scam/empty body, "Order" events
     * which are sent alongside "Payment" events and must be ignored to avoid double
     * crediting, or malformed data).
     */
    public function parseNotification(array $rawPayload): ?HelloAssoNotificationPayload
    {
        $eventType = $rawPayload['eventType'] ?? null;
        $data = $rawPayload['data'] ?? null;

        if ($eventType === null || $data === null || !\is_array($data)) {
            $this->logger->debug('HelloAsso notification: empty or malformed input (must be scam)');

            return null;
        }

        if ($eventType === 'Order') {
            // Both a payment and an order notification are sent by HelloAsso for the
            // same event; only the "Payment" one is processed, to avoid double credit.
            $this->logger->debug('HelloAsso notification: ignoring Order event type');

            return null;
        }

        if ($eventType !== 'Payment') {
            $this->logger->error('HelloAsso notification: unexpected event type {type}', ['type' => $eventType]);

            return null;
        }

        $id = $data['id'] ?? null;
        $amount = $data['amount']['total'] ?? null;
        $date = $data['date'] ?? null;
        $state = $data['state'] ?? null;
        $payer = $data['payer'] ?? null;
        $order = $data['order'] ?? null;

        if ($id === null || $amount === null || $payer === null || $order === null) {
            $this->logger->error('HelloAsso notification: missing required fields');

            return null;
        }

        return new HelloAssoNotificationPayload(
            helloAssoPaymentId: (int) $id,
            amountCents: (int) $amount,
            rawDate: (string) ($date ?? ''),
            state: (string) ($state ?? ''),
            payerFirstName: (string) ($payer['firstName'] ?? ''),
            payerLastName: (string) ($payer['lastName'] ?? ''),
            payerEmail: (string) ($payer['email'] ?? ''),
            formSlug: (string) ($order['formSlug'] ?? ''),
        );
    }

    /**
     * @return HelloAssoFetchedPayment[]
     */
    public function fetchPaymentsHistory(HelloAssoConfig $config, int $nbDays): array
    {
        $token = $this->getAccessToken($config);

        try {
            $now = new \DateTimeImmutable();
            $beginDate = $now->modify(sprintf('-%d days', $nbDays));

            $response = $this->httpClient->request('GET', $this->buildUrl($config, sprintf(
                'v5/organizations/%s/forms/%s/%s/payments',
                rawurlencode($config->getOrganizationSlug()),
                rawurlencode($config->getFormType()),
                rawurlencode($config->getFormSlug()),
            )), [
                'query' => [
                    'from' => $beginDate->format(DATE_ATOM),
                    'to' => $now->format(DATE_ATOM),
                    'states' => 'Authorized',
                ],
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            if ($response->getStatusCode() >= 300) {
                $this->logger->error('HelloAsso payment history fetch failed with status {status} (organization={org}, formType={type}, formSlug={form}): {body}', [
                    'status' => $response->getStatusCode(),
                    'org' => $config->getOrganizationSlug(),
                    'type' => $config->getFormType(),
                    'form' => $config->getFormSlug(),
                    'body' => $response->getContent(false),
                ]);

                return [];
            }

            $body = $response->toArray(false);
            $payments = [];
            foreach ($body['data'] ?? [] as $item) {
                if (!isset($item['id'], $item['amount'])) {
                    continue;
                }
                $payer = $item['payer'] ?? [];
                $payments[] = new HelloAssoFetchedPayment(
                    helloAssoPaymentId: (int) $item['id'],
                    amountCents: (int) $item['amount'],
                    rawDate: (string) ($item['date'] ?? ''),
                    payerFirstName: (string) ($payer['firstName'] ?? ''),
                    payerLastName: (string) ($payer['lastName'] ?? ''),
                    payerEmail: (string) ($payer['email'] ?? ''),
                );
            }

            return $payments;
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->error('Error fetching HelloAsso payment history: {message}', ['message' => $exception->getMessage()]);

            return [];
        } finally {
            $this->disconnect($config, $token);
        }
    }

    /**
     * Looks up an alternative email for a payment, via a custom order item field
     * (used when the payer used a different email than their Cyclos account).
     */
    public function getAlternativeEmail(HelloAssoConfig $config, int $paymentId): ?string
    {
        try {
            $token = $this->getAccessToken($config);
        } catch (HelloAssoException $exception) {
            $this->logger->error('Error fetching HelloAsso token for alternative email lookup: {message}', ['message' => $exception->getMessage()]);

            return null;
        }

        try {
            $paymentResponse = $this->httpClient->request('GET', $this->buildUrl($config, 'v5/payments/' . $paymentId), [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            if ($paymentResponse->getStatusCode() >= 300) {
                $this->logger->error('Error fetching HelloAsso payment {id}', ['id' => $paymentId]);

                return null;
            }

            $orderId = $paymentResponse->toArray(false)['order']['id'] ?? null;
            if ($orderId === null) {
                return null;
            }

            $orderResponse = $this->httpClient->request('GET', $this->buildUrl($config, 'v5/orders/' . $orderId), [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            if ($orderResponse->getStatusCode() >= 300) {
                $this->logger->error('Error fetching HelloAsso order {id}', ['id' => $orderId]);

                return null;
            }

            $items = $orderResponse->toArray(false)['items'] ?? [];
            $fieldName = $config->getExtraMailFieldName();

            foreach ($items as $item) {
                foreach ($item['customFields'] ?? [] as $field) {
                    $matchesFieldName = $fieldName !== null && ($field['name'] ?? null) === $fieldName;
                    $looksLikeEmail = isset($field['answer']) && str_contains((string) $field['answer'], '@');
                    if ($matchesFieldName || $looksLikeEmail) {
                        return (string) $field['answer'];
                    }
                }
            }

            return null;
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->error('Error during HelloAsso alternative email lookup: {message}', ['message' => $exception->getMessage()]);

            return null;
        } finally {
            $this->disconnect($config, $token);
        }
    }

    private function getAccessToken(HelloAssoConfig $config): string
    {
        try {
            $response = $this->httpClient->request('POST', $this->buildUrl($config, 'oauth2/token'), [
                'body' => [
                    'client_id' => $config->getHelloAssoClientId(),
                    'client_secret' => $this->secretEncryptor->decrypt($config->getClientSecretEncrypted()),
                    'grant_type' => 'client_credentials',
                ],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $data = $response->toArray(false);
            $accessToken = $data['access_token'] ?? null;
            if (!\is_string($accessToken) || $accessToken === '') {
                throw new HelloAssoException('HelloAsso token response did not contain an access_token.');
            }

            return $accessToken;
        } catch (HttpClientExceptionInterface $exception) {
            throw new HelloAssoException('Failed to fetch HelloAsso access token: ' . $exception->getMessage(), previous: $exception);
        }
    }

    private function disconnect(HelloAssoConfig $config, string $token): void
    {
        try {
            $this->httpClient->request('GET', $this->buildUrl($config, 'oauth2/disconnect'), [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->debug('Error disconnecting HelloAsso token: {message}', ['message' => $exception->getMessage()]);
        }
    }

    private function buildUrl(HelloAssoConfig $config, string $path): string
    {
        return $config->getApiUrl() . $path;
    }
}
