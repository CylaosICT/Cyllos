<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\HelloAssoConfig;
use App\Entity\Payment;
use App\Entity\PaymentStatus;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HealthControllerTest extends WebTestCase
{
    private KernelBrowser $httpClient;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->httpClient = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\Payment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ClientSetting')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CyclosConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CheckoutOrder')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\StripeConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\HelloAssoConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
    }

    public function testHealthIsPublicAndOkOnACleanSystem(): void
    {
        $this->httpClient->request('GET', '/health');

        self::assertResponseIsSuccessful();
        $body = json_decode($this->httpClient->getResponse()->getContent(), true);

        self::assertContains($body['status'], ['ok', 'degraded']);
        self::assertSame('ok', $body['checks']['database']['status']);
        self::assertArrayHasKey('queue', $body['checks']);
        self::assertArrayHasKey('stuckPayments', $body['checks']);
    }

    public function testHealthReportsDegradedWhenAnAutomaticPaymentIsStuckInTodo(): void
    {
        $client = $this->createAutomaticClient();

        $payment = new Payment(
            client: $client,
            helloAssoConfig: $client->getHelloAssoConfigs()->first(),
            helloAssoPaymentId: 4242,
            paymentDate: new \DateTimeImmutable('-1 hour'),
            amount: 20.0,
            payerFirstName: 'Jean',
            payerLastName: 'Dupont',
            email: 'jean@example.com',
        );
        $payment->setStatus(PaymentStatus::Todo);
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        // Backdate the insertion so it is past the "stuck" threshold.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE payment SET insertion_date = :d WHERE id = :id',
            ['d' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), 'id' => $payment->getId()],
        );

        $this->httpClient->request('GET', '/health');

        self::assertResponseIsSuccessful();
        $body = json_decode($this->httpClient->getResponse()->getContent(), true);

        self::assertSame('degraded', $body['status']);
        self::assertSame('degraded', $body['checks']['stuckPayments']['status']);
        self::assertSame(1, $body['checks']['stuckPayments']['automaticTodoOlderThanThreshold']);
    }

    private function createAutomaticClient(): Client
    {
        /** @var SecretEncryptor $encryptor */
        $encryptor = self::getContainer()->get(SecretEncryptor::class);

        $client = (new Client())->setName('Auto')->setSlug('auto')->setActive(true);
        $client->addHelloAssoConfig((new HelloAssoConfig())
            ->setLabel('Particuliers')
            ->setApiUrl('https://api.helloasso.example/')
            ->setHelloAssoClientId('id')
            ->setClientSecretEncrypted($encryptor->encrypt('secret'))
            ->setOrganizationSlug('org')
            ->setFormSlug('form')
            ->setMaxAmount(250)
            ->setFetchNbDays(5));
        $client->setCyclosConfig((new CyclosConfig())
            ->setBaseUrl('https://cyclos.example/api/')
            ->setTechnicalUserId('1')
            ->setPasswordEncrypted($encryptor->encrypt('pwd'))
            ->setGroupProInternal('pro')
            ->setGroupsPartInternal('part')
            ->setEmissionProInternal('emission.Pro')
            ->setEmissionPartInternal('emission.Part'));
        $client->setSetting((new ClientSetting())
            ->setPaymentCyclosEnabled(true)
            ->setPaymentAutomaticEnabled(true)
            ->setMailRecipient('ops@example.com'));

        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $client;
    }
}
