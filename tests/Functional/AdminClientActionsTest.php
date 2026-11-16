<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\HelloAssoConfig;
use App\Entity\User;
use App\Message\FetchClientPaymentsMessage;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Small POST actions on the admin client page — driven through the real forms so
 * the CSRF tokens they carry are exercised too.
 */
class AdminClientActionsTest extends WebTestCase
{
    private KernelBrowser $httpClient;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->httpClient = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\Payment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ClientSetting')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CyclosConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CheckoutOrder')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\StripeConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\HelloAssoConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())->setEmail('a-' . uniqid() . '@e.test')->setPassword('x')->setRoles(['ROLE_ADMIN']);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->httpClient->loginUser($admin);
    }

    private function persistClient(): Client
    {
        /** @var SecretEncryptor $encryptor */
        $encryptor = self::getContainer()->get(SecretEncryptor::class);

        $client = (new Client())->setName('Sync Co')->setSlug('sync-co')->setActive(true);
        $client->addHelloAssoConfig((new HelloAssoConfig())
            ->setLabel('P')->setApiUrl('https://x/')->setHelloAssoClientId('id')
            ->setClientSecretEncrypted($encryptor->encrypt('s'))
            ->setOrganizationSlug('o')->setFormSlug('f')->setMaxAmount(250)->setFetchNbDays(5));
        $client->setCyclosConfig((new CyclosConfig())
            ->setBaseUrl('https://c/')->setTechnicalUserId('1')->setPasswordEncrypted($encryptor->encrypt('p'))
            ->setGroupProInternal('p')->setGroupsPartInternal('q')->setEmissionProInternal('a')->setEmissionPartInternal('b'));
        $client->setSetting((new ClientSetting())->setPaymentCyclosEnabled(false)->setPaymentAutomaticEnabled(false)->setMailRecipient('o@e.test'));
        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $client;
    }

    public function testSyncButtonQueuesAnAutoCreditingFetchInsteadOfRunningItInline(): void
    {
        $client = $this->persistClient();
        $this->loginAsAdmin();

        $crawler = $this->httpClient->request('GET', '/admin/clients/' . $client->getId());
        $this->httpClient->submit($crawler->filter('form[action$="/fetch"]')->form());

        self::assertResponseRedirects('/admin/clients/' . $client->getId());

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = array_values(array_filter(
            $transport->getSent(),
            static fn ($e) => $e->getMessage() instanceof FetchClientPaymentsMessage,
        ));

        self::assertCount(1, $messages);
        self::assertSame($client->getId(), $messages[0]->getMessage()->clientId);
        self::assertTrue($messages[0]->getMessage()->attemptAutomaticCredit);
    }

    public function testRegenerateWebhookTokenChangesTheToken(): void
    {
        $client = $this->persistClient();
        $originalToken = $client->getWebhookToken();
        $this->loginAsAdmin();

        $crawler = $this->httpClient->request('GET', '/admin/clients/' . $client->getId());
        $this->httpClient->submit($crawler->filter('form[action$="/webhook/regenerer-jeton"]')->form());

        self::assertResponseRedirects('/admin/clients/' . $client->getId());

        $this->entityManager->clear();
        $reloaded = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Client::class)->find($client->getId());
        self::assertNotSame($originalToken, $reloaded->getWebhookToken());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $reloaded->getWebhookToken());
    }
}
