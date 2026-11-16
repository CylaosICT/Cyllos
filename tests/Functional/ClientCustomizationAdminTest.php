<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\ClientCustomization;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\HelloAssoConfig;
use App\Entity\User;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The admin "Personnalisation" tab: saving creates the ClientCustomization row
 * on first use, folds the per-type template fields into the JSON column, and
 * rejects an unknown %placeholder%.
 */
class ClientCustomizationAdminTest extends WebTestCase
{
    private KernelBrowser $httpClient;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->httpClient = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\Payment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ClientCustomization')->execute();
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

        $client = (new Client())->setName('La Cigogne')->setSlug('la-cigogne')->setActive(true);
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

    public function testSavingCreatesTheRowAndStoresOverrides(): void
    {
        $client = $this->persistClient();
        $this->loginAsAdmin();

        $crawler = $this->httpClient->request('GET', '/admin/clients/' . $client->getId() . '/personnalisation');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['client_customization[cyclosDescriptionPrefix]'] = 'Recharge instantanée ';
        $form['client_customization[emailSubjectPrefix]'] = '[Cigogne]';
        $form['client_customization[success__subject]'] = 'Recharge confirmée';
        $form['client_customization[success__body]'] = 'Bonjour %payer%, %amount% € crédités (réf. %id%).';
        $this->httpClient->submit($form);

        self::assertResponseRedirects('/admin/clients/' . $client->getId());

        $this->entityManager->clear();
        $customization = $this->entityManager->getRepository(ClientCustomization::class)->findOneBy(['client' => $client->getId()]);
        self::assertInstanceOf(ClientCustomization::class, $customization);
        self::assertSame('Recharge instantanée ', $customization->getCyclosDescriptionPrefix());
        self::assertSame('[Cigogne]', $customization->getEmailSubjectPrefix());
        self::assertSame(
            ['success' => ['subject' => 'Recharge confirmée', 'body' => 'Bonjour %payer%, %amount% € crédités (réf. %id%).']],
            $customization->getEmailTemplates(),
        );
    }

    public function testUnknownPlaceholderIsRejected(): void
    {
        $client = $this->persistClient();
        $this->loginAsAdmin();

        $crawler = $this->httpClient->request('GET', '/admin/clients/' . $client->getId() . '/personnalisation');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['client_customization[failure__body]'] = 'Paiement %id% en échec pour %totally_unknown%.';
        $this->httpClient->submit($form);

        self::assertResponseStatusCodeSame(422);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(ClientCustomization::class)->findOneBy(['client' => $client->getId()]));
    }
}
