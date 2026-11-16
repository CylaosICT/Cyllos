<?php

namespace App\Tests\Functional;

use App\Entity\CheckoutOrder;
use App\Entity\CheckoutOrderStatus;
use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\Payment;
use App\Entity\PaymentProvider;
use App\Entity\PaymentStatus;
use App\Entity\StripeConfig;
use App\Message\ProcessPaymentMessage;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

class StripeWebhookControllerTest extends WebTestCase
{
    private const WEBHOOK_SECRET = 'whsec_functionaltest0123456789';

    private KernelBrowser $httpClient;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->httpClient = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        foreach (['Payment', 'EmailAlias', 'User', 'ClientSetting', 'CyclosConfig', 'CheckoutOrder', 'StripeConfig', 'HelloAssoConfig', 'Client'] as $entity) {
            $this->entityManager->createQuery("DELETE FROM App\\Entity\\$entity")->execute();
        }
    }

    private function createStripeClient(bool $automatic = false): Client
    {
        /** @var SecretEncryptor $encryptor */
        $encryptor = self::getContainer()->get(SecretEncryptor::class);

        $client = (new Client())->setName('Stripe Client')->setSlug('stripe-client')->setActive(true)->setPaymentProvider(PaymentProvider::Stripe);

        $stripe = (new StripeConfig())
            ->setSecretKeyEncrypted($encryptor->encrypt('rk_test_abcdefghijklmnop'))
            ->setWebhookSecretEncrypted($encryptor->encrypt(self::WEBHOOK_SECRET))
            ->setMinAmount(5)
            ->setMaxAmount(250);
        $client->setStripeConfig($stripe);

        $client->setCyclosConfig(
            (new CyclosConfig())
                ->setBaseUrl('https://cyclos.example/api/')
                ->setTechnicalUserId('1')
                ->setPasswordEncrypted($encryptor->encrypt('pwd'))
                ->setGroupProInternal('pro')
                ->setGroupsPartInternal('part')
                ->setEmissionProInternal('emission.Pro')
                ->setEmissionPartInternal('emission.Part'),
        );
        $client->setSetting(
            (new ClientSetting())
                ->setPaymentCyclosEnabled(false)
                ->setPaymentAutomaticEnabled($automatic)
                ->setMailRecipient('ops@example.com'),
        );

        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $client;
    }

    private function createOrder(Client $client, int $amountCents = 2000, string $sessionId = 'cs_test_abc'): CheckoutOrder
    {
        $order = new CheckoutOrder($client, 'payer@example.com', $amountCents);
        $order->setStripeSessionId($sessionId);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    /**
     * @param array<string, mixed> $sessionOverrides
     */
    private function event(string $type, CheckoutOrder $order, array $sessionOverrides = []): string
    {
        return json_encode([
            'id' => 'evt_' . uniqid(),
            'type' => $type,
            'created' => time(),
            'data' => ['object' => array_replace([
                'id' => $order->getStripeSessionId(),
                'object' => 'checkout.session',
                'client_reference_id' => $order->getReference(),
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_123',
                'amount_total' => $order->getAmountCents(),
                'currency' => 'eur',
                'customer_details' => ['name' => 'Jeanne Payeuse', 'email' => 'payer@example.com'],
            ], $sessionOverrides)],
        ], \JSON_THROW_ON_ERROR);
    }

    private function post(string $slug, string $body, ?string $signature = null, string $secret = self::WEBHOOK_SECRET): void
    {
        $timestamp = time();
        $signature ??= 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        $this->httpClient->request('POST', '/webhook/stripe/' . $slug, server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], content: $body);
    }

    private function paymentCount(): int
    {
        return $this->entityManager->getRepository(Payment::class)->count([]);
    }

    public function testPaidSessionRecordsAStripePayment(): void
    {
        $client = $this->createStripeClient();
        $order = $this->createOrder($client);

        $this->post($client->getSlug(), $this->event('checkout.session.completed', $order));

        self::assertResponseIsSuccessful();
        self::assertSame('recorded', json_decode($this->httpClient->getResponse()->getContent(), true)['status']);

        $this->entityManager->clear();
        $payments = $this->entityManager->getRepository(Payment::class)->findAll();
        self::assertCount(1, $payments);

        $payment = $payments[0];
        self::assertSame(PaymentProvider::Stripe, $payment->getProvider());
        self::assertSame(PaymentStatus::Todo, $payment->getStatus());
        self::assertSame($order->getReference(), $payment->getReference());
        self::assertSame(20.0, $payment->getAmount());
        self::assertSame('payer@example.com', $payment->getEmail());
        self::assertSame('Jeanne Payeuse', $payment->getPayerLastName());
        self::assertNull($payment->getHelloAssoConfig());
        self::assertSame('Stripe', $payment->getSourceLabel());

        $savedOrder = $this->entityManager->getRepository(CheckoutOrder::class)->find($order->getId());
        self::assertSame(CheckoutOrderStatus::Paid, $savedOrder->getStatus());
        self::assertSame('pi_test_123', $savedOrder->getStripePaymentIntentId());
    }

    public function testRedeliveryDoesNotCreateASecondPayment(): void
    {
        $client = $this->createStripeClient();
        $order = $this->createOrder($client);
        $body = $this->event('checkout.session.completed', $order);

        $this->post($client->getSlug(), $body);
        $this->post($client->getSlug(), $body);

        self::assertResponseIsSuccessful();
        self::assertSame('ignored', json_decode($this->httpClient->getResponse()->getContent(), true)['status']);
        self::assertSame(1, $this->paymentCount());
    }

    public function testAutomaticClientQueuesTheCyclosCredit(): void
    {
        $client = $this->createStripeClient(automatic: true);
        $order = $this->createOrder($client);

        $this->post($client->getSlug(), $this->event('checkout.session.completed', $order));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent());
        self::assertCount(1, $messages);
        self::assertInstanceOf(ProcessPaymentMessage::class, $messages[0]);
    }

    public function testManualClientDoesNotQueueAnything(): void
    {
        $client = $this->createStripeClient(automatic: false);
        $order = $this->createOrder($client);

        $this->post($client->getSlug(), $this->event('checkout.session.completed', $order));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertCount(0, $transport->getSent());
    }

    public function testInvalidSignatureIsRejectedAndRecordsNothing(): void
    {
        $client = $this->createStripeClient();
        $order = $this->createOrder($client);

        $this->post($client->getSlug(), $this->event('checkout.session.completed', $order), secret: 'whsec_not_the_right_one');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->paymentCount());
    }

    public function testMissingSignatureIsRejected(): void
    {
        $client = $this->createStripeClient();
        $order = $this->createOrder($client);

        $this->httpClient->request('POST', '/webhook/stripe/' . $client->getSlug(), content: $this->event('checkout.session.completed', $order));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->paymentCount());
    }

    public function testUnknownOrInactiveOrHelloAssoClientIsNotFound(): void
    {
        $this->post('nobody', '{}', 't=1,v1=00');
        self::assertResponseStatusCodeSame(404);

        $client = $this->createStripeClient();
        $client->setActive(false);
        $this->entityManager->flush();
        $this->post($client->getSlug(), '{}', 't=1,v1=00');
        self::assertResponseStatusCodeSame(404);

        $client->setActive(true)->setPaymentProvider(PaymentProvider::HelloAsso);
        $this->entityManager->flush();
        $this->post($client->getSlug(), '{}', 't=1,v1=00');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnpaidSessionWaitsForTheAsyncConfirmation(): void
    {
        $client = $this->createStripeClient();
        $order = $this->createOrder($client);

        $this->post($client->getSlug(), $this->event('checkout.session.completed', $order, ['payment_status' => 'unpaid']));

        self::assertSame('awaiting_payment', json_decode($this->httpClient->getResponse()->getContent(), true)['status']);
        self::assertSame(0, $this->paymentCount());

        $this->post($client->getSlug(), $this->event('checkout.session.async_payment_succeeded', $order));
        self::assertSame(1, $this->paymentCount());
    }

    public function testAmountMismatchIsRecordedAsFailedNeverCredited(): void
    {
        $client = $this->createStripeClient(automatic: true);
        $order = $this->createOrder($client, 2000);

        $this->post($client->getSlug(), $this->event('checkout.session.completed', $order, ['amount_total' => 100]));

        $this->entityManager->clear();
        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy([]);
        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Fail, $payment->getStatus());
        self::assertNotEmpty($payment->getError());

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertCount(0, $transport->getSent(), 'A mismatching payment must never be queued for automatic credit.');
    }

    public function testSessionIdMismatchIsIgnored(): void
    {
        $client = $this->createStripeClient();
        $order = $this->createOrder($client);

        $this->post($client->getSlug(), $this->event('checkout.session.completed', $order, ['id' => 'cs_test_other']));

        self::assertSame(0, $this->paymentCount());
    }

    public function testUnknownOrderReferenceIsIgnored(): void
    {
        $client = $this->createStripeClient();
        $order = $this->createOrder($client);

        $this->post($client->getSlug(), $this->event('checkout.session.completed', $order, ['client_reference_id' => 'S-NOPE']));

        self::assertSame(0, $this->paymentCount());
    }

    public function testExpiredSessionClosesAPendingOrderOnly(): void
    {
        $client = $this->createStripeClient();
        $pending = $this->createOrder($client, 2000, 'cs_test_pending');
        $paid = $this->createOrder($client, 3000, 'cs_test_paid');
        $paid->markPaid('pi_x', new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->post($client->getSlug(), $this->event('checkout.session.expired', $pending));
        $this->post($client->getSlug(), $this->event('checkout.session.expired', $paid));

        $this->entityManager->clear();
        $repo = $this->entityManager->getRepository(CheckoutOrder::class);
        self::assertSame(CheckoutOrderStatus::Expired, $repo->find($pending->getId())->getStatus());
        self::assertSame(CheckoutOrderStatus::Paid, $repo->find($paid->getId())->getStatus());
    }

    public function testUnhandledEventTypesAreAcknowledged(): void
    {
        $client = $this->createStripeClient();
        $order = $this->createOrder($client);

        $this->post($client->getSlug(), $this->event('customer.created', $order));

        self::assertResponseIsSuccessful();
        self::assertSame('ignored', json_decode($this->httpClient->getResponse()->getContent(), true)['status']);
    }
}
