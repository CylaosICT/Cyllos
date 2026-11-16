<?php

namespace App\Tests\Unit\Integration\HelloAsso;

use App\ActivityLog\ApiCallLogger;
use App\Integration\HelloAsso\HelloAssoClient;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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
            new ApiCallLogger($this->createStub(EntityManagerInterface::class)),
            new ArrayAdapter(),
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
                'order' => ['formSlug' => 'rollon-form'],
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
        self::assertSame('rollon-form', $result->formSlug);
    }

    public function testParsesFlatIntegerAmount(): void
    {
        // The shape HelloAsso actually sends in a "Payment" webhook: data.amount
        // is a flat integer of cents, not a {total, vat, discount} object.
        $result = $this->client->parseNotification($this->validPayload([
            'data' => ['amount' => 2000],
        ]));

        self::assertNotNull($result);
        self::assertSame(2000, $result->amountCents);
    }

    public function testParsesRealPaymentWebhookPayload(): void
    {
        $payload = [
            'data' => [
                'cardExpirationDate' => '2034-12-31T00:00:00Z',
                'order' => [
                    'id' => 96646,
                    'formSlug' => 'cyllos',
                    'formType' => 'Shop',
                    'organizationSlug' => 'cylaos-ict',
                ],
                'payer' => ['email' => 'support@cylaos.com', 'country' => 'FRA', 'firstName' => 'Eric', 'lastName' => 'DE BEL-AIR'],
                'items' => [['shareAmount' => 2000, 'amount' => 2000, 'id' => 104740, 'type' => 'Product', 'state' => 'Processed']],
                'cashOutState' => 'Transfered',
                'id' => 67736,
                'amount' => 2000,
                'date' => '2026-09-03T18:59:50.8630226+02:00',
                'state' => 'Authorized',
                'refundOperations' => [],
            ],
            'eventType' => 'Payment',
        ];

        $result = $this->client->parseNotification($payload);

        self::assertNotNull($result);
        self::assertSame(67736, $result->helloAssoPaymentId);
        self::assertSame(2000, $result->amountCents);
        self::assertSame('Authorized', $result->state);
        self::assertSame('support@cylaos.com', $result->payerEmail);
        self::assertSame('cyllos', $result->formSlug);
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
