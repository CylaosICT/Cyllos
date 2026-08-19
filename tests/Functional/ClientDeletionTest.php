<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\EmailAlias;
use App\Entity\HelloAssoConfig;
use App\Entity\Payment;
use App\Entity\User;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Deleting a client must clean up every row that has a foreign key pointing
 * at it (payments, user accounts, EmailAlias rules) before removing the
 * client row itself — otherwise the database rejects the delete with a
 * foreign key constraint violation, surfacing as an uncaught 500. This
 * happened for real once EmailAlias was added without updating
 * ClientController::delete() to clean it up too.
 */
class ClientDeletionTest extends WebTestCase
{
    private KernelBrowser $httpClient;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->httpClient = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\EmailAlias')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Payment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ClientSetting')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CyclosConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\HelloAssoConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
    }

    public function testDeletingAClientAlsoRemovesItsEmailAliasesWithoutForeignKeyError(): void
    {
        /** @var SecretEncryptor $encryptor */
        $encryptor = self::getContainer()->get(SecretEncryptor::class);

        $client = (new Client())->setName('Delete Me')->setSlug('delete-me')->setActive(false);

        $client->setHelloAssoConfig((new HelloAssoConfig())
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
            ->setPaymentCyclosEnabled(false)
            ->setPaymentAutomaticEnabled(false)
            ->setMailRecipient('ops@example.com'));

        $this->entityManager->persist($client);

        $payment = new Payment(
            client: $client,
            helloAssoPaymentId: 1,
            paymentDate: new \DateTimeImmutable(),
            amount: 10.0,
            payerFirstName: 'Jean',
            payerLastName: 'Dupont',
            email: 'jean@example.com',
        );
        $this->entityManager->persist($payment);

        $clientUser = new User();
        $clientUser->setEmail('user@delete-me.example')->setPassword('irrelevant')->setRoles(['ROLE_CLIENT'])->setClient($client);
        $this->entityManager->persist($clientUser);

        $alias = (new EmailAlias())->setClient($client)->setSourceEmail('payer@example.com')->setTargetEmail('cyclos@example.com');
        $this->entityManager->persist($alias);

        $admin = new User();
        $admin->setEmail('admin@delete-me.example')->setPassword('irrelevant')->setRoles(['ROLE_ADMIN']);
        $this->entityManager->persist($admin);

        $this->entityManager->flush();
        $clientId = $client->getId();

        $this->httpClient->loginUser($admin);
        $crawler = $this->httpClient->request('GET', '/admin/clients/' . $clientId);
        $deleteForm = $crawler->filter('form[action="/admin/clients/' . $clientId . '/supprimer"]')->form();

        $this->httpClient->submit($deleteForm);

        self::assertResponseRedirects('/admin/clients');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Client::class)->find($clientId));
        self::assertCount(0, $this->entityManager->getRepository(EmailAlias::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(Payment::class)->findAll());
    }
}
