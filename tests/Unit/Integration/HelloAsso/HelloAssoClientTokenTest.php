<?php

namespace App\Tests\Unit\Integration\HelloAsso;

use App\ActivityLog\ApiCallLogger;
use App\Entity\HelloAssoConfig;
use App\Integration\HelloAsso\HelloAssoClient;
use App\Integration\HelloAsso\HelloAssoRateLimitedException;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class HelloAssoClientTokenTest extends TestCase
{
    private SecretEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->encryptor = new SecretEncryptor(base64_encode(str_repeat('a', 32)));
    }

    private function config(): HelloAssoConfig
    {
        return (new HelloAssoConfig())
            ->setApiUrl('https://api.helloasso.test/')
            ->setHelloAssoClientId('client-id')
            ->setClientSecretEncrypted($this->encryptor->encrypt('secret'))
            ->setOrganizationSlug('org')
            ->setFormType('Checkout')
            ->setFormSlug('form');
    }

    private function client(callable $responses, int &$requests): HelloAssoClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, $responses) {
            ++$requests;

            return $responses($method, $url, $options);
        });

        return new HelloAssoClient(
            $http,
            $this->encryptor,
            new NullLogger(),
            new ApiCallLogger($this->createStub(EntityManagerInterface::class)),
            new ArrayAdapter(),
        );
    }

    public function testTokenIsFetchedOnceAndReusedAcrossCalls(): void
    {
        $requests = 0;
        $client = $this->client(static function (string $method, string $url) {
            return str_contains($url, 'oauth2/token')
                ? new MockResponse('{"access_token":"tok","expires_in":1800}')
                : new MockResponse('{"data":[]}');
        }, $requests);

        $client->fetchPaymentsHistory($this->config(), 7);
        $client->fetchPaymentsHistory($this->config(), 7);
        $client->fetchPaymentsHistory($this->config(), 7);

        // 1 token request + 3 payment requests (no token refetch, no /oauth2/disconnect).
        self::assertSame(4, $requests);
    }

    public function testRateLimitedTokenRequestThrowsAndSetsCooldown(): void
    {
        $requests = 0;
        $client = $this->client(static fn () => new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 120']]), $requests);

        try {
            $client->fetchPaymentsHistory($this->config(), 7);
            self::fail('Expected HelloAssoRateLimitedException');
        } catch (HelloAssoRateLimitedException $e) {
            self::assertSame(120, $e->retryAfterSeconds);
        }

        self::assertSame(1, $requests);

        // Second call must not hit the API again while cooling down.
        try {
            $client->fetchPaymentsHistory($this->config(), 7);
            self::fail('Expected HelloAssoRateLimitedException');
        } catch (HelloAssoRateLimitedException $e) {
            self::assertGreaterThan(0, $e->retryAfterSeconds);
        }

        self::assertSame(1, $requests);
    }
}
