<?php

namespace App\Deployment;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Drives the payment site (cyllos-pay, a separate server) from the back-office:
 * reads its version and starts its update. Calls are signed like the payment
 * site's own calls to Cyllos: HMAC-SHA256 of "{timestamp}.{raw body}" with the
 * shared PAY_INTERNAL_SECRET. The payment site runs its own fixed update script;
 * nothing sent from here chooses what is executed.
 */
class PaySiteUpdater
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $paySiteBaseUrl,
        private readonly string $internalSecret,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->paySiteBaseUrl !== '' && $this->internalSecret !== '';
    }

    public function status(): PaySiteStatus
    {
        try {
            $data = $this->call('/internal/update/status');
        } catch (\RuntimeException $e) {
            return new PaySiteStatus(PaySiteStatus::STATE_UNKNOWN, errorMessage: $e->getMessage());
        }

        $behind = (int) ($data['behind'] ?? 0);
        $ahead = (int) ($data['ahead'] ?? 0);
        $known = \is_string($data['commit'] ?? null) && \is_string($data['remoteCommit'] ?? null);
        $date = \is_string($data['remoteDate'] ?? null) ? date_create_immutable($data['remoteDate']) : false;

        return new PaySiteStatus(
            state: match (true) {
                !$known => PaySiteStatus::STATE_UNKNOWN,
                $behind > 0 => PaySiteStatus::STATE_BEHIND,
                $ahead > 0 => PaySiteStatus::STATE_AHEAD,
                default => PaySiteStatus::STATE_UP_TO_DATE,
            },
            localCommit: \is_string($data['commit'] ?? null) ? $data['commit'] : null,
            remoteCommit: \is_string($data['remoteCommit'] ?? null) ? $data['remoteCommit'] : null,
            remoteCommitDate: $date !== false ? $date : null,
            behindBy: $behind,
            aheadBy: $ahead,
            running: (bool) ($data['running'] ?? false),
            lastRun: \is_string($data['state'] ?? null) ? $data['state'] : 'never',
            log: \is_string($data['log'] ?? null) ? $data['log'] : '',
            errorMessage: \is_string($data['error'] ?? null) ? $data['error'] : null,
            branch: \is_string($data['branch'] ?? null) ? $data['branch'] : 'main',
        );
    }

    /**
     * @return bool false when an update was already running
     *
     * @throws \RuntimeException when the payment site cannot be reached or refuses
     */
    public function deploy(): bool
    {
        return (bool) ($this->call('/internal/update/deploy')['started'] ?? false);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function call(string $path): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Site de paiement non configuré (PAY_SITE_BASE_URL / PAY_INTERNAL_SECRET).');
        }

        $body = '{}';
        $timestamp = (string) time();

        try {
            $response = $this->httpClient->request('POST', rtrim($this->paySiteBaseUrl, '/') . $path, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Cyllos-Timestamp' => $timestamp,
                    'X-Cyllos-Signature' => hash_hmac('sha256', $timestamp . '.' . $body, $this->internalSecret),
                ],
                'body' => $body,
                'timeout' => 45,
            ]);

            $code = $response->getStatusCode();
            if ($code === 401) {
                throw new \RuntimeException('Le site de paiement a refusé la signature : PAY_INTERNAL_SECRET différent des deux côtés, ou horloges désynchronisées.');
            }
            if ($code === 404) {
                throw new \RuntimeException("Le site de paiement n'a pas encore la fonction de mise à jour : faites une première mise à jour à la main (git pull + deploy/update.sh).");
            }
            if ($code !== 200) {
                throw new \RuntimeException(\sprintf('Le site de paiement a répondu avec le code %d.', $code));
            }

            return $response->toArray(false);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Site de paiement injoignable : ' . $e->getMessage(), 0, $e);
        }
    }
}
