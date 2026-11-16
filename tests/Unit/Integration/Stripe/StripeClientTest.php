<?php

namespace App\Tests\Unit\Integration\Stripe;

use App\ActivityLog\ApiCallLogger;
use App\Entity\CheckoutOrder;
use App\Entity\Client;
use App\Entity\StripeConfig;
use App\Integration\Stripe\StripeClient;
use App\Integration\Stripe\StripeException;
use App\Security\SecretEncryptor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class StripeClientTest extends TestCase
{
    private const API_KEY = 'rk_test_abcdefghijklmnop';

    private SecretEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->encryptor = new SecretEncryptor(base64_encode(random_bytes(32)));
    }

    private function config(): StripeConfig
    {
        return (new StripeConfig())->setSecretKeyEncrypted($this->encryptor->encrypt(self::API_KEY));
    }

    private function order(): CheckoutOrder
    {
        return new CheckoutOrder((new Client())->setName('Le RolloN')->setSlug('rollon'), 'payer@example.com', 2550);
    }

    public function testCreatesAHostedPageSessionWithTheExpectedRequest(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(json_encode(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']), ['http_code' => 200]);
        });
        $logger = $this->createMock(ApiCallLogger::class);
        $logger->expects(self::once())->method('record')->with(
            'stripe',
            'POST',
            'https://api.stripe.com/v1/checkout/sessions',
            self::callback(static fn (?string $body): bool => !str_contains((string) $body, self::API_KEY)),
            200,
            self::anything(),
            self::anything(),
        );

        $order = $this->order();
        $session = (new StripeClient($http, $this->encryptor, new NullLogger(), $logger))
            ->createCheckoutSession($this->config(), $order, 'Alimentation', 'https://pay.example/rollon/retour/X', 'https://pay.example/rollon?annule=X');

        self::assertSame('cs_test_1', $session->id);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $session->url);

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.stripe.com/v1/checkout/sessions', $captured['url']);

        $headers = implode("\n", $captured['options']['headers']);
        self::assertStringContainsString('Authorization: Bearer ' . self::API_KEY, $headers);
        self::assertStringContainsString('Stripe-Version: ' . StripeClient::API_VERSION, $headers);
        self::assertStringContainsString('Idempotency-Key: ' . $order->getReference(), $headers);

        parse_str($captured['options']['body'], $body);
        self::assertSame('payment', $body['mode']);
        self::assertSame('hosted_page', $body['ui_mode']);
        // Payment methods are managed from the Stripe Dashboard: the parameter is no longer accepted.
        self::assertArrayNotHasKey('payment_method_types', $body);
        self::assertSame('payer@example.com', $body['customer_email']);
        self::assertSame($order->getReference(), $body['client_reference_id']);
        self::assertSame($order->getReference(), $body['metadata']['order_reference']);
        self::assertSame('eur', $body['line_items'][0]['price_data']['currency']);
        self::assertSame('2550', $body['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('1', $body['line_items'][0]['quantity']);
        self::assertSame('https://pay.example/rollon/retour/X', $body['success_url']);
        self::assertSame('https://pay.example/rollon?annule=X', $body['cancel_url']);
        self::assertGreaterThanOrEqual(time() + 1800, (int) $body['expires_at']);
    }

    public function testApiErrorBecomesAStripeException(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['error' => ['message' => 'Invalid API Key provided']]), ['http_code' => 401]));

        $this->expectException(StripeException::class);
        $this->expectExceptionMessage('Invalid API Key provided');

        (new StripeClient($http, $this->encryptor, new NullLogger(), $this->createStub(ApiCallLogger::class)))
            ->createCheckoutSession($this->config(), $this->order(), 'x', 'https://a', 'https://b');
    }

    public function testAResponseWithoutUrlIsRejected(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['id' => 'cs_test_1']), ['http_code' => 200]));

        $this->expectException(StripeException::class);

        (new StripeClient($http, $this->encryptor, new NullLogger(), $this->createStub(ApiCallLogger::class)))
            ->createCheckoutSession($this->config(), $this->order(), 'x', 'https://a', 'https://b');
    }

    public function testNetworkFailureBecomesAStripeException(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['error' => 'connection refused']));

        $this->expectException(StripeException::class);

        (new StripeClient($http, $this->encryptor, new NullLogger(), $this->createStub(ApiCallLogger::class)))
            ->createCheckoutSession($this->config(), $this->order(), 'x', 'https://a', 'https://b');
    }
}
