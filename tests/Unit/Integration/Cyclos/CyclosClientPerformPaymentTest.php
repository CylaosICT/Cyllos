<?php

namespace App\Tests\Unit\Integration\Cyclos;

use App\ActivityLog\ApiCallLogger;
use App\Entity\CyclosConfig;
use App\Integration\Cyclos\CyclosClient;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class CyclosClientPerformPaymentTest extends TestCase
{
    private SecretEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->encryptor = new SecretEncryptor(base64_encode(str_repeat('a', 32)));
    }

    private function makeClient(MockHttpClient $httpClient): CyclosClient
    {
        return new CyclosClient(
            $httpClient,
            $this->encryptor,
            new NullLogger(),
            new ApiCallLogger($this->createStub(EntityManagerInterface::class)),
        );
    }

    private function makeConfig(): CyclosConfig
    {
        return (new CyclosConfig())
            ->setBaseUrl('https://cyclos.example/api/')
            ->setTechnicalUserId('1')
            ->setPasswordEncrypted($this->encryptor->encrypt('pwd'));
    }

    public function testSuccessfulPaymentHitsTheRealEndpointAndReportsSuccess(): void
    {
        $seen = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$seen): MockResponse {
            $seen = [$method, $url];

            return new MockResponse('{"id":"tx1"}', ['http_code' => 200]);
        });

        $result = $this->makeClient($httpClient)->performPayment(
            $this->makeConfig(),
            'payer@example.com',
            25.0,
            'Recharge instantanée N° 42',
            'emission.Part',
            preview: false,
        );

        self::assertTrue($result->success);
        self::assertSame('POST', $seen[0]);
        self::assertSame('https://cyclos.example/api/system/payments', $seen[1]);
    }

    public function testPreviewPaymentTargetsThePreviewEndpoint(): void
    {
        $seen = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$seen): MockResponse {
            $seen = $url;

            return new MockResponse('{}', ['http_code' => 200]);
        });

        $result = $this->makeClient($httpClient)->performPayment(
            $this->makeConfig(),
            'payer@example.com',
            25.0,
            'desc',
            'emission.Part',
            preview: true,
        );

        self::assertTrue($result->success);
        self::assertSame('https://cyclos.example/api/system/payments/preview', $seen);
    }

    public function testAnHttpErrorFromCyclosIsReportedAsAFailure(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{"code":"validation"}', ['http_code' => 422]));

        $result = $this->makeClient($httpClient)->performPayment(
            $this->makeConfig(),
            'payer@example.com',
            25.0,
            'desc',
            'emission.Part',
            preview: false,
        );

        self::assertFalse($result->success);
        self::assertNotNull($result->errorMessage);
    }
}
