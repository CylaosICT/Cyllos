<?php

namespace App\Tests\Unit\Integration\HelloAsso;

use App\Integration\HelloAsso\HelloAssoClient;
use App\Security\SecretEncryptor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

class HelloAssoClientParseNotificationTest extends TestCase
{
    private HelloAssoClient $client;

    protected function setUp(): void
    {
        $this->client = new HelloAssoClient(
            new MockHttpClient(),
            new SecretEncryptor(base64_encode(str_repeat('a', 32))),
            new NullLogger(),
        );
    }

    private function validPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'eventType' => 'Payment',
            'data' => [
                'id' => 987654,
                'amount' => ['total' => 2000],
                'date' => '2026-08-15T10:00:00+02:00',
                'state' => 'Authorized',
                'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@example.com'],
                'order' => ['formSlug' => 'reelon-form'],
            ],
        ], $overrides);
    }

    public function testParsesAWellFormedPaymentNotification(): void
    {
        $result = $this->client->parseNotification($this->validPayload());

        self::assertNotNull($result);
        self::assertSame(987654, $result->helloAssoPaymentId);
        self::assertSame(2000, $result->amountCents);
        self::assertSame('Authorized', $result->state);
        self::assertSame('jean@example.com', $result->payerEmail);
        self::assertSame('reelon-form', $result->formSlug);
    }

    public function testIgnoresOrderEventTypeToAvoidDoubleCredit(): void
    {
        self::assertNull($this->client->parseNotification($this->validPayload(['eventType' => 'Order'])));
    }

    public function testIgnoresUnknownEventType(): void
    {
        self::assertNull($this->client->parseNotification($this->validPayload(['eventType' => 'Refund'])));
    }

    public function testIgnoresEmptyPayload(): void
    {
        self::assertNull($this->client->parseNotification([]));
    }

    public function testIgnoresPayloadMissingData(): void
    {
        self::assertNull($this->client->parseNotification(['eventType' => 'Payment']));
    }

    public function testIgnoresPayloadMissingRequiredFields(): void
    {
        $payload = $this->validPayload();
        unset($payload['data']['payer']);

        self::assertNull($this->client->parseNotification($payload));
    }
}
