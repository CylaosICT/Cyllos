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
        $this->entityManager->createQuery('DELETE FROM App\Entity\HelloAssoConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
    }

    private function createTestClient(bool $automatic = false): Client
    {
        /** @var SecretEncryptor $encryptor */
        $encryptor = self::getContainer()->get(SecretEncryptor::class);

        $client = (new Client())->setName('Test Client')->setSlug('test-client')->setActive(true);

        $haConfig = (new HelloAssoConfig())
            ->setApiUrl('https://api.helloasso.com/')
            ->setHelloAssoClientId('id')
            ->setClientSecretEncrypted($encryptor->encrypt('secret'))
            ->setOrganizationSlug('org')
            ->setFormSlug('test-form')
            ->setMaxAmount(250)
            ->setFetchNbDays(5);
        $client->setHelloAssoConfig($haConfig);

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
            '/webhook/helloasso/' . $client->getSlug(),
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

    public function testWebhookIgnoresOrderEventType(): void
    {
        $client = $this->createTestClient();

        $this->httpClient->request(
            'POST',
            '/webhook/helloasso/' . $client->getSlug(),
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
            '/webhook/helloasso/' . $client->getSlug(),
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
            '/webhook/helloasso/does-not-exist',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->paymentPayload()),
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testWebhookDoesNotDuplicateAnAlreadyKnownPayment(): void
    {
        $client = $this->createTestClient();

        $payload = json_encode($this->paymentPayload());

        $this->httpClient->request('POST', '/webhook/helloasso/' . $client->getSlug(), server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        $this->httpClient->request('POST', '/webhook/helloasso/' . $client->getSlug(), server: ['CONTENT_TYPE' => 'application/json'], content: $payload);

        self::assertResponseIsSuccessful();

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = self::getContainer()->get(PaymentRepository::class);
        self::assertCount(1, $paymentRepository->findAllForClient($client));
    }
}
