<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\HelloAssoConfig;
use App\Entity\PaymentStatus;
use App\Repository\PaymentRepository;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WebhookControllerTest extends WebTestCase
{
    private KernelBrowser $httpClient;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->httpClient = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // Clean slate for each test.
        $this->entityManager->createQuery('DELETE FROM App\Entity\Payment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ClientSetting')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CyclosConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CheckoutOrder')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\StripeConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\HelloAssoConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
    }

    private function createTestClient(bool $automatic = false): Client
    {
        /** @var SecretEncryptor $encryptor */
        $encryptor = self::getContainer()->get(SecretEncryptor::class);

        $client = (new Client())->setName('Test Client')->setSlug('test-client')->setActive(true);

        $haConfig = (new HelloAssoConfig())
            ->setLabel('Particuliers')
            ->setApiUrl('https://api.helloasso.com/')
            ->setHelloAssoClientId('id')
            ->setClientSecretEncrypted($encryptor->encrypt('secret'))
            ->setOrganizationSlug('org')
            ->setFormSlug('test-form')
            ->setMaxAmount(250)
            ->setFetchNbDays(5);
        $client->addHelloAssoConfig($haConfig);

        $cyclosConfig = (new CyclosConfig())
            ->setBaseUrl('https://cyclos.example/api/')
            ->setTechnicalUserId('1')
            ->setPasswordEncrypted($encryptor->encrypt('pwd'))
            ->setGroupProInternal('pro')
            ->setGroupsPartInternal('part')
            ->setEmissionProInternal('emission.Pro')
            ->setEmissionPartInternal('emission.Part');
        $client->setCyclosConfig($cyclosConfig);

        $setting = (new ClientSetting())
            ->setPaymentCyclosEnabled(false)
            ->setPaymentAutomaticEnabled($automatic)
            ->setMailRecipient('ops@example.com');
        $client->setSetting($setting);

        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $client;
    }

    private function addHelloAssoConfig(Client $client, string $formSlug, int $maxAmount = 250, bool $active = true): HelloAssoConfig
    {
        /** @var SecretEncryptor $encryptor */
        $encryptor = self::getContainer()->get(SecretEncryptor::class);

        $config = (new HelloAssoConfig())
            ->setLabel('Professionnels')
            ->setActive($active)
            ->setApiUrl('https://api.helloasso.com/')
            ->setHelloAssoClientId('id-' . $formSlug)
            ->setClientSecretEncrypted($encryptor->encrypt('secret'))
            ->setOrganizationSlug('org')
            ->setFormSlug($formSlug)
            ->setMaxAmount($maxAmount)
            ->setFetchNbDays(5);

        $client->addHelloAssoConfig($config);
        $this->entityManager->persist($config);
        $this->entityManager->flush();

        return $config;
    }

    private function webhookPath(Client $client): string
    {
        return '/webhook/helloasso/' . $client->getSlug() . '/' . $client->getWebhookToken();
    }

    private function paymentPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'eventType' => 'Payment',
            'data' => [
                'id' => 111222,
                'amount' => ['total' => 2000],
                'date' => '2026-08-15T10:00:00+02:00',
                'state' => 'Authorized',
                'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@example.com'],
                'order' => ['formSlug' => 'test-form'],
            ],
        ], $overrides);
    }

    public function testWebhookCreatesATodoPaymentInManualMode(): void
    {
        $client = $this->createTestClient(automatic: false);

        $this->httpClient->request(
            'POST',
            $this->webhookPath($client),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->paymentPayload()),
        );

        self::assertResponseIsSuccessful();

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = self::getContainer()->get(PaymentRepository::class);
        $payment = $paymentRepository->findOneByClientAndHelloAssoId($client, 111222);

        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Todo, $payment->getStatus());
        self::assertSame(20.0, $payment->getAmount());
    }

    /**
     * In automatic mode the webhook must not attempt the Cyclos credit inline: it
     * persists the payment as "todo" and hands the rest to a worker via
     * ProcessPaymentMessage, so HelloAsso gets a fast response.
     */
    public function testWebhookDefersCreditingToAMessageInAutomaticMode(): void
    {
        $client = $this->createTestClient(automatic: true);

        $this->httpClient->request(
            'POST',
            $this->webhookPath($client),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->paymentPayload()),
        );

        self::assertResponseIsSuccessful();

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = self::getContainer()->get(PaymentRepository::class);
        $payment = $paymentRepository->findOneByClientAndHelloAssoId($client, 111222);

        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Todo, $payment->getStatus());

        /** @var \Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $processMessages = array_filter(
            $transport->getSent(),
            static fn (\Symfony\Component\Messenger\Envelope $e) => $e->getMessage() instanceof \App\Message\ProcessPaymentMessage,
        );
        self::assertCount(1, $processMessages);
        self::assertSame($payment->getId(), reset($processMessages)->getMessage()->paymentId);
    }

    public function testWebhookIgnoresOrderEventType(): void
    {
        $client = $this->createTestClient();

        $this->httpClient->request(
            'POST',
            $this->webhookPath($client),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->paymentPayload(['eventType' => 'Order'])),
        );

        self::assertResponseIsSuccessful();

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = self::getContainer()->get(PaymentRepository::class);
        self::assertNull($paymentRepository->findOneByClientAndHelloAssoId($client, 111222));
    }

    public function testWebhookMarksOverLimitPaymentAsTooHigh(): void
    {
        $client = $this->createTestClient();

        $this->httpClient->request(
            'POST',
            $this->webhookPath($client),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->paymentPayload(['data' => ['amount' => ['total' => 100000]]])),
        );

        self::assertResponseIsSuccessful();

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = self::getContainer()->get(PaymentRepository::class);
        $payment = $paymentRepository->findOneByClientAndHelloAssoId($client, 111222);

        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::TooHigh, $payment->getStatus());
    }

    public function testWebhookReturnsNotFoundForUnknownClientSlug(): void
    {
        $this->httpClient->request(
            'POST',
            '/webhook/helloasso/does-not-exist/' . str_repeat('a', 64),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->paymentPayload()),
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testWebhookReturnsNotFoundForWrongTokenWithoutCreatingAPayment(): void
    {
        $client = $this->createTestClient();

        $this->httpClient->request(
            'POST',
            '/webhook/helloasso/' . $client->getSlug() . '/' . str_repeat('b', 64),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->paymentPayload()),
        );

        self::assertResponseStatusCodeSame(404);

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = self::getContainer()->get(PaymentRepository::class);
        self::assertNull($paymentRepository->findOneByClientAndHelloAssoId($client, 111222));
    }

    /**
     * The webhook route is throttled per client slug via the "webhook" rate
     * limiter (config/packages/rate_limiter.yaml). The test env bucket is small
     * (5, see config/packages/test/rate_limiter.yaml); exercised directly on the
     * factory the controller is wired to, since the functional HTTP client resets
     * the in-memory limiter store between requests.
     */
    public function testWebhookRateLimiterRejectsOnceTheClientBucketIsEmpty(): void
    {
        /** @var \Symfony\Component\RateLimiter\RateLimiterFactoryInterface $factory */
        $factory = self::getContainer()->get('limiter.webhook');
        $limiter = $factory->create('some-client-slug');

        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($limiter->consume()->isAccepted(), "consume #$i should be accepted");
        }

        self::assertFalse($limiter->consume()->isAccepted());
        // A different slug has its own independent bucket.
        self::assertTrue($factory->create('another-client-slug')->consume()->isAccepted());
    }

    public function testLegacyTokenlessUrlIsRejectedWithoutCreatingAPayment(): void
    {
        $client = $this->createTestClient();

        $this->httpClient->request(
            'POST',
            '/webhook/helloasso/' . $client->getSlug(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->paymentPayload()),
        );

        self::assertResponseStatusCodeSame(404);

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = self::getContainer()->get(PaymentRepository::class);
        self::assertNull($paymentRepository->findOneByClientAndHelloAssoId($client, 111222));
    }

    public function testLegacyTokenlessUrlStampsTheClientForTheDashboard(): void
    {
        $client = $this->createTestClient();
        self::assertNull($client->getLegacyWebhookLastSeenAt());

        $this->httpClient->request(
            'POST',
            '/webhook/helloasso/' . $client->getSlug(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->paymentPayload()),
        );

        self::assertResponseStatusCodeSame(404);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Client::class)->find($client->getId());
        self::assertNotNull($reloaded->getLegacyWebhookLastSeenAt());
    }

    public function testWebhookDoesNotDuplicateAnAlreadyKnownPayment(): void
    {
        $client = $this->createTestClient();

        $payload = json_encode($this->paymentPayload());

        $this->httpClient->request('POST', $this->webhookPath($client), server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        $this->httpClient->request('POST', $this->webhookPath($client), server: ['CONTENT_TYPE' => 'application/json'], content: $payload);

        self::assertResponseIsSuccessful();

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = self::getContainer()->get(PaymentRepository::class);
        self::assertCount(1, $paymentRepository->findAllForClient($client));
    }

    /**
     * A client with two active forms (e.g. "Particuliers"/"Professionnels")
     * must route each webhook to the config matching the payload's formSlug —
     * not just whichever config happens to be first. The second config here
     * has a much lower max amount specifically so a wrong match would flip
     * this payment to TooHigh instead of Todo.
     */
    public function testWebhookRoutesToTheMatchingFormWhenClientHasTwoActiveForms(): void
    {
        $client = $this->createTestClient();
        $this->addHelloAssoConfig($client, 'test-form-pro', maxAmount: 10);

        $this->httpClient->request(
            'POST',
            $this->webhookPath($client),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->paymentPayload()), // formSlug: test-form, amount: 20€
        );

        self::assertResponseIsSuccessful();

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = self::getContainer()->get(PaymentRepository::class);
        $payment = $paymentRepository->findOneByClientAndHelloAssoId($client, 111222);

        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Todo, $payment->getStatus());
        self::assertSame('test-form', $payment->getHelloAssoConfig()->getFormSlug());
    }

    public function testWebhookIsIgnoredForADeactivatedForm(): void
    {
        $client = $this->createTestClient();
        $client->getHelloAssoConfigs()->first()->setActive(false);
        $this->entityManager->flush();

        $this->httpClient->request(
            'POST',
            $this->webhookPath($client),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->paymentPayload()),
        );

        self::assertResponseIsSuccessful();

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = self::getContainer()->get(PaymentRepository::class);
        self::assertNull($paymentRepository->findOneByClientAndHelloAssoId($client, 111222));
    }
}
