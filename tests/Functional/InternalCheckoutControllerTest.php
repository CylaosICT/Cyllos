<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\PaymentProvider;
use App\Entity\StripeConfig;
use App\Payment\CheckoutException;
use App\Payment\CheckoutService;
use App\Payment\CheckoutStart;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class InternalCheckoutControllerTest extends WebTestCase
{
    private const SECRET = 'test-internal-secret-0123456789abcdef0123456789abcdef';

    private KernelBrowser $httpClient;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->httpClient = static::createClient();
        $this->httpClient->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        foreach (['Payment', 'EmailAlias', 'User', 'ClientSetting', 'CyclosConfig', 'CheckoutOrder', 'StripeConfig', 'HelloAssoConfig', 'Client'] as $entity) {
            $this->entityManager->createQuery("DELETE FROM App\\Entity\\$entity")->execute();
        }

        $client = (new Client())->setName('Stripe Client')->setSlug('stripe-client')->setActive(true)->setPaymentProvider(PaymentProvider::Stripe);
        $client->setStripeConfig(new StripeConfig());
        $client->setCyclosConfig((new CyclosConfig())->setBaseUrl('https://cyclos.example/api/')->setTechnicalUserId('1'));
        $client->setSetting((new ClientSetting())->setMailRecipient('ops@example.com'));
        $this->entityManager->persist($client);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function call(array $payload, ?string $signature = null, ?int $timestamp = null, bool $omitHeaders = false, string $path = '/internal/checkout'): void
    {
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $signature ??= hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);

        $server = $omitHeaders ? [] : ['HTTP_X_CYLLOS_TIMESTAMP' => (string) $timestamp, 'HTTP_X_CYLLOS_SIGNATURE' => $signature];
        $this->httpClient->request('POST', $path, server: $server + ['CONTENT_TYPE' => 'application/json'], content: $body);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace(['clientSlug' => 'stripe-client', 'email' => 'payer' . bin2hex(random_bytes(4)) . '@example.com', 'amountCents' => 2000, 'clientIp' => '203.0.113.' . random_int(1, 250)], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode($this->httpClient->getResponse()->getContent(), true);
    }

    private function stubService(?\Closure $onStart = null, ?CheckoutException $throw = null): void
    {
        $service = $this->createMock(CheckoutService::class);
        $method = $service->method('start');
        $check = $service->method('checkAccount');
        if ($throw !== null) {
            $method->willThrowException($throw);
            $check->willThrowException($throw);
        } else {
            $method->willReturnCallback($onStart ?? static fn () => new CheckoutStart('S-ABCDEFGHJK', 'https://checkout.stripe.com/c/pay/cs_test_1'));
            $check->willReturn('individual');
        }
        self::getContainer()->set(CheckoutService::class, $service);
    }

    public function testSignedCallStartsACheckout(): void
    {
        $this->stubService(function (Client $client, string $email, int $amountCents): CheckoutStart {
            self::assertSame('stripe-client', $client->getSlug());
            self::assertStringStartsWith('payer', $email);
            self::assertSame(2000, $amountCents);

            return new CheckoutStart('S-ABCDEFGHJK', 'https://checkout.stripe.com/c/pay/cs_test_1');
        });

        $this->call($this->validPayload());

        self::assertResponseIsSuccessful();
        self::assertSame(['reference' => 'S-ABCDEFGHJK', 'redirectUrl' => 'https://checkout.stripe.com/c/pay/cs_test_1'], $this->json());
    }

    public function testRejectsAWrongSignature(): void
    {
        $this->call($this->validPayload(), signature: str_repeat('a', 64));

        self::assertResponseStatusCodeSame(401);
    }

    public function testRejectsAMissingSignature(): void
    {
        $this->call($this->validPayload(), omitHeaders: true);

        self::assertResponseStatusCodeSame(401);
    }

    public function testRejectsAStaleTimestampEvenWithAValidSignature(): void
    {
        $this->call($this->validPayload(), timestamp: time() - 3600);

        self::assertResponseStatusCodeSame(401);
    }

    public function testRejectsABodyAlteredAfterSigning(): void
    {
        $payload = $this->validPayload();
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . json_encode($payload), self::SECRET);
        $payload['amountCents'] = 1;

        $this->call($payload, signature: $signature, timestamp: $timestamp);

        self::assertResponseStatusCodeSame(401);
    }

    public function testRejectsAMalformedPayload(): void
    {
        $this->call(['clientSlug' => 'stripe-client']);
        self::assertResponseStatusCodeSame(400);

        $this->call($this->validPayload(['amountCents' => '2000']));
        self::assertResponseStatusCodeSame(400);
    }

    public function testBusinessErrorsAreReportedWithAStableReason(): void
    {
        $this->stubService(throw: new CheckoutException(CheckoutException::ACCOUNT_NOT_FOUND));
        $this->call($this->validPayload());
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'account_not_found'], $this->json());
    }

    public function testProviderErrorsAreABadGateway(): void
    {
        $this->stubService(throw: new CheckoutException(CheckoutException::PROVIDER_ERROR, 'stripe down'));
        $this->call($this->validPayload());
        self::assertResponseStatusCodeSame(502);
        self::assertSame(['error' => 'provider_error'], $this->json(), 'The provider message must not leak to the payment site.');
    }

    public function testUnknownClientIsUnavailable(): void
    {
        $this->stubService();
        $this->call($this->validPayload(['clientSlug' => 'nobody']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'client_unavailable'], $this->json());
    }

    public function testTheSameEmailIsRateLimited(): void
    {
        $this->stubService();
        $spam = 'spam' . bin2hex(random_bytes(4)) . '@example.com';

        for ($i = 0; $i < 5; ++$i) {
            $this->call($this->validPayload(['email' => $spam]));
            self::assertResponseIsSuccessful();
        }

        $this->call($this->validPayload(['email' => $spam]));
        self::assertResponseStatusCodeSame(429);

        // Another address is unaffected.
        $this->call($this->validPayload(['email' => 'other' . bin2hex(random_bytes(4)) . '@example.com']));
        self::assertResponseIsSuccessful();
    }

    public function testTheSamePayerIpIsRateLimited(): void
    {
        $this->stubService();
        $ip = '198.51.100.' . random_int(1, 250);

        for ($i = 0; $i < 20; ++$i) {
            $this->call($this->validPayload(['clientIp' => $ip, 'email' => "user$i" . bin2hex(random_bytes(3)) . '@example.com']));
            self::assertResponseIsSuccessful();
        }

        $this->call($this->validPayload(['clientIp' => $ip, 'email' => 'user99' . bin2hex(random_bytes(3)) . '@example.com']));
        self::assertResponseStatusCodeSame(429);
    }
    public function testAccountCheckReturnsTheAccountType(): void
    {
        $this->stubService();

        $this->call(['clientSlug' => 'stripe-client', 'email' => 'payer' . bin2hex(random_bytes(4)) . '@example.com', 'clientIp' => '203.0.113.7'], path: '/internal/account-check');

        self::assertResponseIsSuccessful();
        self::assertSame(['accountType' => 'individual'], $this->json());
    }

    public function testAccountCheckRequiresASignatureAndAValidBody(): void
    {
        $payload = ['clientSlug' => 'stripe-client', 'email' => 'a@b.fr', 'clientIp' => '203.0.113.7'];

        $this->call($payload, signature: str_repeat('a', 64), path: '/internal/account-check');
        self::assertResponseStatusCodeSame(401);

        $this->call(['clientSlug' => 'stripe-client'], path: '/internal/account-check');
        self::assertResponseStatusCodeSame(400);
    }

    public function testAccountCheckReportsAnUnknownAccountAndIsRateLimited(): void
    {
        $this->stubService(throw: new CheckoutException(CheckoutException::ACCOUNT_NOT_FOUND));
        $email = 'ghost' . bin2hex(random_bytes(4)) . '@example.com';

        for ($i = 0; $i < 10; ++$i) {
            $this->call(['clientSlug' => 'stripe-client', 'email' => $email, 'clientIp' => '203.0.113.' . random_int(1, 250)], path: '/internal/account-check');
            self::assertResponseStatusCodeSame(422);
            self::assertSame(['error' => 'account_not_found'], $this->json());
        }

        $this->call(['clientSlug' => 'stripe-client', 'email' => $email, 'clientIp' => '203.0.113.9'], path: '/internal/account-check');
        self::assertResponseStatusCodeSame(429);
    }
}
