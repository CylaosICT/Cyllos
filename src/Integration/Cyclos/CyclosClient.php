<?php

namespace App\Integration\Cyclos;

use App\Entity\CyclosConfig;
use App\Security\SecretEncryptor;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to a client's Cyclos instance to look up users and perform system-to-user
 * payments. Ported from CyclosService.java, parameterized by CyclosConfig instead
 * of a single global .env configuration.
 */
class CyclosClient
{
    public const PAYMENT_DESCRIPTION_PREFIX = 'Paiement automatique, id technique ';

    private const REQUEST_TIMEOUT = 60.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretEncryptor $secretEncryptor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function findUserByEmail(CyclosConfig $config, string $email): ?CyclosUser
    {
        try {
            $response = $this->request($config, 'GET', 'users', [
                'query' => [
                    'fields' => '',
                    'keywords' => $email,
                    'roles' => 'member',
                    'statuses' => 'active',
                    'includeGroup' => 'true',
                ],
            ]);

            if ($response->getStatusCode() >= 300) {
                return null;
            }

            $users = $response->toArray(false);
            if (\count($users) !== 1 || !isset($users[0]['id'])) {
                return null;
            }

            $groupInternalName = $users[0]['group']['internalName'] ?? null;
            if ($groupInternalName === null) {
                return null;
            }

            return new CyclosUser((string) $users[0]['id'], $groupInternalName);
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->error('Error fetching Cyclos user: {message}', ['message' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * Resolves the emission type to use for a payment, based on the user's Cyclos
     * group (professional vs. one of the possible "particulier" groups). Returns
     * null when the group is not authorized to receive automatic credits.
     */
    public function resolveEmissionType(CyclosConfig $config, CyclosUser $user): ?string
    {
        if ($user->groupInternalName === $config->getGroupProInternal()) {
            return $config->getEmissionProInternal();
        }

        if (\in_array($user->groupInternalName, $config->getGroupsPartInternalList(), true)) {
            return $config->getEmissionPartInternal();
        }

        return null;
    }

    /**
     * Anti-duplicate check: looks at the user's last credit transaction and compares
     * its description to the one we would use for this payment.
     */
    public function hasAlreadyCreditedPayment(CyclosConfig $config, string $email, string $expectedDescription): bool
    {
        try {
            $response = $this->request($config, 'GET', rawurlencode($email) . '/transactions', [
                'query' => [
                    'fields' => 'description',
                    'authorizationStatuses' => 'authorized',
                    'direction' => 'credit',
                    'kinds' => '',
                    'orderBy' => 'dateDesc',
                    'page' => 1,
                    'pageSize' => 1,
                ],
            ]);

            if ($response->getStatusCode() >= 300) {
                return false;
            }

            $transactions = $response->toArray(false);
            if ($transactions === []) {
                return false;
            }

            return ($transactions[0]['description'] ?? null) === $expectedDescription;
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->error('Error checking Cyclos duplicate payment: {message}', ['message' => $exception->getMessage()]);

            return false;
        }
    }

    public function performPayment(
        CyclosConfig $config,
        string $email,
        float $amount,
        string $description,
        string $emissionType,
        bool $preview,
    ): CyclosPaymentResult {
        try {
            $response = $this->request($config, 'POST', $preview ? 'system/payments/preview' : 'system/payments', [
                'json' => [
                    'amount' => (string) $amount,
                    'to' => $email,
                    'description' => $description,
                    'type' => $emissionType,
                ],
            ]);

            if ($response->getStatusCode() >= 300) {
                $this->logger->error('Cyclos payment failed with status {status}: {body}', [
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(false),
                ]);

                return CyclosPaymentResult::failure('Erreur technique lors du paiement dans Cyclos (HTTP ' . $response->getStatusCode() . ')');
            }

            return CyclosPaymentResult::success($preview);
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->error('Error performing Cyclos payment: {message}', ['message' => $exception->getMessage()]);

            return CyclosPaymentResult::failure('Erreur technique inattendue lors du paiement dans Cyclos');
        }
    }

    private function request(CyclosConfig $config, string $method, string $path, array $options = []): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $options['auth_basic'] = [
            $config->getTechnicalUserId(),
            $this->secretEncryptor->decrypt($config->getPasswordEncrypted()),
        ];
        $options['timeout'] = self::REQUEST_TIMEOUT;
        $options['headers'] = ['Accept' => 'application/json'];

        return $this->httpClient->request($method, $config->getBaseUrl() . $path, $options);
    }
}
