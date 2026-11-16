<?php

namespace App\Integration\HelloAsso;

use App\ActivityLog\ApiCallLogger;
use App\Entity\HelloAssoConfig;
use App\Security\SecretEncryptor;
use Psr\Cache\CacheItemPoolInterface;
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

    /** Safety margin (s) removed from the token lifetime so we never use an about-to-expire token. */
    private const TOKEN_TTL_MARGIN = 120;
    private const TOKEN_DEFAULT_TTL = 1500;
    private const RATE_LIMIT_DEFAULT_WAIT = 60;
    private const RATE_LIMIT_MAX_WAIT = 900;
    private const RATE_LIMIT_CACHE_KEY = 'helloasso_oauth_rate_limited';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretEncryptor $secretEncryptor,
        private readonly LoggerInterface $logger,
        private readonly ApiCallLogger $apiCallLogger,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Structurally parses a "Payment" webhook body. Returns null for anything that
     * isn't a well-formed payment notification (scam/empty body, "Order" events
     * which are sent alongside "Payment" events and must be ignored to avoid double
     * crediting, or malformed data).
     *
     * `data.amount` is accepted both as a flat integer of cents (the shape
     * HelloAsso sends in a "Payment" webhook, and what the v5 payments API
     * returns) and as a `{total, vat, discount}` object (the shape carried on the
     * "Order" event and older payloads).
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
        $rawAmount = $data['amount'] ?? null;
        $amount = \is_array($rawAmount) ? ($rawAmount['total'] ?? null) : $rawAmount;
        $date = $data['date'] ?? null;
        $state = $data['state'] ?? null;
        $payer = $data['payer'] ?? null;
        $order = $data['order'] ?? null;

        if ($id === null || !is_numeric($amount) || !\is_array($payer) || !\is_array($order)) {
            $this->logger->error('HelloAsso notification: missing or malformed required fields', [
                'hasId' => $id !== null,
                'amountType' => get_debug_type($rawAmount),
                'hasPayer' => \is_array($payer),
                'hasOrder' => \is_array($order),
            ]);

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
            $beginDate = $now->modify(\sprintf('-%d days', $nbDays));
            $query = [
                'from' => $beginDate->format(DATE_ATOM),
                'to' => $now->format(DATE_ATOM),
                'states' => 'Authorized',
            ];
            $url = $this->buildUrl($config, \sprintf(
                'v5/organizations/%s/forms/%s/%s/payments',
                rawurlencode($config->getOrganizationSlug()),
                rawurlencode($config->getFormType()),
                rawurlencode($config->getFormSlug()),
            ));

            $response = $this->httpClient->request('GET', $url, [
                'query' => $query,
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $this->apiCallLogger->record('helloasso', 'GET', $url, json_encode($query, JSON_PRETTY_PRINT), $response->getStatusCode(), $response->getContent(false), 'Récupération des paiements HelloAsso');

            if ($response->getStatusCode() === 401) {
                $this->forgetAccessToken($config);
            }

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
            $this->apiCallLogger->record('helloasso', 'GET', $this->buildUrl($config, 'v5/organizations/.../payments'), null, 0, $exception->getMessage());

            return [];
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
            $paymentUrl = $this->buildUrl($config, 'v5/payments/' . $paymentId);
            $paymentResponse = $this->httpClient->request('GET', $paymentUrl, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);
            $this->apiCallLogger->record('helloasso', 'GET', $paymentUrl, null, $paymentResponse->getStatusCode(), $paymentResponse->getContent(false), 'Recherche email alternatif — paiement');

            if ($paymentResponse->getStatusCode() === 401) {
                $this->forgetAccessToken($config);
            }

            if ($paymentResponse->getStatusCode() >= 300) {
                $this->logger->error('Error fetching HelloAsso payment {id}', ['id' => $paymentId]);

                return null;
            }

            $orderId = $paymentResponse->toArray(false)['order']['id'] ?? null;
            if ($orderId === null) {
                return null;
            }

            $orderUrl = $this->buildUrl($config, 'v5/orders/' . $orderId);
            $orderResponse = $this->httpClient->request('GET', $orderUrl, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);
            $this->apiCallLogger->record('helloasso', 'GET', $orderUrl, null, $orderResponse->getStatusCode(), $orderResponse->getContent(false), 'Recherche email alternatif — commande');

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
        }
    }

    /**
     * Returns a bearer token for this configuration, reusing a cached one while it
     * is valid: requesting a fresh token for every API call is what triggered
     * HTTP 429 from HelloAsso's /oauth2/token endpoint when many fetches ran at once.
     * The cached value is encrypted at rest.
     *
     * @throws HelloAssoRateLimitedException when HelloAsso is throttling token requests
     * @throws HelloAssoException            on any other token failure
     */
    private function getAccessToken(HelloAssoConfig $config): string
    {
        $tokenItem = $this->cache->getItem($this->tokenCacheKey($config));
        if ($tokenItem->isHit()) {
            try {
                return $this->secretEncryptor->decrypt((string) $tokenItem->get());
            } catch (\Throwable) {
                // Unreadable cache entry (e.g. key rotation): fall through and refetch.
            }
        }

        // Cool-down after a 429: don't send more requests to the token endpoint.
        $blockItem = $this->cache->getItem(self::RATE_LIMIT_CACHE_KEY);
        if ($blockItem->isHit()) {
            $wait = max(1, (int) $blockItem->get() - time());

            throw new HelloAssoRateLimitedException(\sprintf('HelloAsso token endpoint is rate limited, cooling down for %d more second(s).', $wait), $wait);
        }

        [$accessToken, $ttl] = $this->requestAccessToken($config);

        $tokenItem->set($this->secretEncryptor->encrypt($accessToken));
        $tokenItem->expiresAfter($ttl);
        $this->cache->save($tokenItem);

        return $accessToken;
    }

    private function forgetAccessToken(HelloAssoConfig $config): void
    {
        $this->cache->deleteItem($this->tokenCacheKey($config));
    }

    private function tokenCacheKey(HelloAssoConfig $config): string
    {
        return 'helloasso_token_' . sha1($config->getApiUrl() . '|' . $config->getHelloAssoClientId());
    }

    /**
     * The request contains client_id/client_secret and the response contains a
     * live bearer token — neither is safe to store in the audit trail, so this
     * call is logged with both bodies redacted, keeping only the method/URL/
     * status for traceability.
     *
     * @return array{0: string, 1: int} the token and its cache lifetime in seconds
     */
    private function requestAccessToken(HelloAssoConfig $config): array
    {
        $url = $this->buildUrl($config, 'oauth2/token');
        $redactedRequest = '(identifiants OAuth2 — non journalisés)';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'body' => [
                    'client_id' => $config->getHelloAssoClientId(),
                    'client_secret' => $this->secretEncryptor->decrypt($config->getClientSecretEncrypted()),
                    'grant_type' => 'client_credentials',
                ],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $status = $response->getStatusCode();

            if ($status === 429) {
                $wait = $this->parseRetryAfter($response->getHeaders(false)['retry-after'][0] ?? null);
                $this->apiCallLogger->record('helloasso', 'POST', $url, $redactedRequest, $status, 'Trop de requêtes (429)', 'Authentification OAuth2 — limitée');

                $blockItem = $this->cache->getItem(self::RATE_LIMIT_CACHE_KEY);
                $blockItem->set(time() + $wait);
                $blockItem->expiresAfter($wait);
                $this->cache->save($blockItem);

                throw new HelloAssoRateLimitedException(\sprintf('HelloAsso token endpoint returned HTTP 429, retry in %d second(s).', $wait), $wait);
            }

            $data = $response->toArray(false);
            $accessToken = $data['access_token'] ?? null;

            if (!\is_string($accessToken) || $accessToken === '') {
                // Not a token response — most likely a standard OAuth2 error body
                // (e.g. {"error":"invalid_client","error_description":"..."}), which
                // never contains a secret, unlike a real token response. Safe to log
                // and surface as-is, so a misconfigured client_id/secret is
                // diagnosable from the journal/exception instead of a bare "no
                // access_token" message.
                $reason = $this->describeTokenError($data);
                $this->apiCallLogger->record('helloasso', 'POST', $url, $redactedRequest, $status, $reason, 'Authentification OAuth2 — échec');

                throw new HelloAssoException(\sprintf('HelloAsso token response did not contain an access_token (HTTP %d): %s', $status, $reason));
            }

            $this->apiCallLogger->record('helloasso', 'POST', $url, $redactedRequest, $status, '(jeton d\'accès — non journalisé)', 'Authentification OAuth2');

            $expiresIn = isset($data['expires_in']) && is_numeric($data['expires_in']) ? (int) $data['expires_in'] : self::TOKEN_DEFAULT_TTL + self::TOKEN_TTL_MARGIN;

            return [$accessToken, max(60, $expiresIn - self::TOKEN_TTL_MARGIN)];
        } catch (HttpClientExceptionInterface $exception) {
            $this->apiCallLogger->record('helloasso', 'POST', $url, $redactedRequest, 0, $exception->getMessage(), 'Authentification OAuth2');

            throw new HelloAssoException('Failed to fetch HelloAsso access token: ' . $exception->getMessage(), previous: $exception);
        }
    }

    private function parseRetryAfter(?string $header): int
    {
        $seconds = self::RATE_LIMIT_DEFAULT_WAIT;
        if ($header !== null && is_numeric($header)) {
            $seconds = (int) $header;
        } elseif ($header !== null && ($date = strtotime($header)) !== false) {
            $seconds = $date - time();
        }

        return max(self::RATE_LIMIT_DEFAULT_WAIT, min(self::RATE_LIMIT_MAX_WAIT, $seconds));
    }

    /**
     * Extracts only the standard OAuth2 error fields ("error" / "error_description")
     * from a failed token response — never the raw body, in case HelloAsso ever
     * echoes back something unexpected. Falls back to a generic message if the
     * response doesn't even look like a recognizable OAuth2 error.
     */
    private function describeTokenError(array $data): string
    {
        $error = $data['error'] ?? null;
        $description = $data['error_description'] ?? null;

        if (\is_string($error) && $error !== '') {
            return \is_string($description) && $description !== ''
                ? \sprintf('%s (%s)', $error, $description)
                : $error;
        }

        return 'réponse inattendue de HelloAsso (ni access_token, ni erreur OAuth2 reconnaissable)';
    }

    private function buildUrl(HelloAssoConfig $config, string $path): string
    {
        return $config->getApiUrl() . $path;
    }
}
