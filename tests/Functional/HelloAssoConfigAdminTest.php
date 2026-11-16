<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\HelloAssoConfig;
use App\Entity\Payment;
use App\Entity\User;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers the admin CRUD around a client's (possibly several) HelloAsso forms:
 * adding a second form pre-fills shared credentials from the first and
 * reuses its secret when left blank, editing, toggling active/inactive (with
 * the "never zero active forms" guard), and deleting — which requires the
 * form to already be deactivated (a safety net against deleting a live one)
 * and to have no payment history, two rules that together also guarantee a
 * client always keeps at least one form without needing a separate check.
 */
class HelloAssoConfigAdminTest extends WebTestCase
{
    private KernelBrowser $httpClient;
    private EntityManagerInterface $entityManager;
    private SecretEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->httpClient = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->encryptor = self::getContainer()->get(SecretEncryptor::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\Payment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ClientSetting')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CyclosConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CheckoutOrder')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\StripeConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\HelloAssoConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
    }

    private function createClientWithOneForm(): Client
    {
        $client = (new Client())->setName('Multi Forms')->setSlug('multi-forms-' . uniqid())->setActive(true);

        $haConfig = (new HelloAssoConfig())
            ->setLabel('Particuliers')
            ->setApiUrl('https://api.helloasso.com/')
            ->setHelloAssoClientId('shared-client-id')
            ->setClientSecretEncrypted($this->encryptor->encrypt('shared-secret'))
            ->setOrganizationSlug('shared-org')
            ->setFormSlug('form-part')
            ->setMaxAmount(250)
            ->setFetchNbDays(5);
        $client->addHelloAssoConfig($haConfig);

        $client->setCyclosConfig((new CyclosConfig())
            ->setBaseUrl('https://cyclos.example/api/')
            ->setTechnicalUserId('1')
            ->setPasswordEncrypted($this->encryptor->encrypt('pwd'))
            ->setGroupProInternal('pro')
            ->setGroupsPartInternal('part')
            ->setEmissionProInternal('emission.Pro')
            ->setEmissionPartInternal('emission.Part'));

        $client->setSetting((new ClientSetting())
            ->setPaymentCyclosEnabled(false)
            ->setPaymentAutomaticEnabled(false)
            ->setMailRecipient('ops@example.com'));

        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $client;
    }

    private function addSecondForm(Client $client): HelloAssoConfig
    {
        $config = (new HelloAssoConfig())
            ->setLabel('Professionnels')
            ->setApiUrl('https://api.helloasso.com/')
            ->setHelloAssoClientId('shared-client-id')
            ->setClientSecretEncrypted($this->encryptor->encrypt('shared-secret'))
            ->setOrganizationSlug('shared-org')
            ->setFormSlug('form-pro')
            ->setMaxAmount(250)
            ->setFetchNbDays(5);
        $client->addHelloAssoConfig($config);
        $this->entityManager->persist($config);
        $this->entityManager->flush();

        return $config;
    }

    private function loginAsAdmin(): void
    {
        $admin = new User();
        $admin->setEmail('admin-' . uniqid() . '@multi-forms.example')->setPassword('irrelevant')->setRoles(['ROLE_ADMIN']);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        $this->httpClient->loginUser($admin);
    }

    /**
     * Submits a POST-only action form (toggle/delete) exactly as a browser
     * would: crawl the client's show page, find that form by its `action`,
     * and submit it — the CSRF token embedded by that same request/session is
     * what makes it valid, unlike a token minted out-of-band.
     */
    private function submitActionForm(int $clientId, string $action): void
    {
        $crawler = $this->httpClient->request('GET', '/admin/clients/' . $clientId);
        $form = $crawler->filter('form[action="' . $action . '"]')->form();
        $this->httpClient->submit($form);
    }

    public function testAddingASecondFormPrefillsSharedCredentialsAndReusesTheSecretWhenLeftBlank(): void
    {
        $client = $this->createClientWithOneForm();
        $this->loginAsAdmin();

        $crawler = $this->httpClient->request('GET', '/admin/clients/' . $client->getId() . '/helloasso/new');
        self::assertResponseIsSuccessful();

        self::assertSame('https://api.helloasso.com/', $crawler->filter('input[name="hello_asso_config[apiUrl]"]')->attr('value'));
        self::assertSame('shared-client-id', $crawler->filter('input[name="hello_asso_config[helloAssoClientId]"]')->attr('value'));
        self::assertSame('shared-org', $crawler->filter('input[name="hello_asso_config[organizationSlug]"]')->attr('value'));

        $form = $crawler->selectButton('Ajouter le formulaire')->form();
        $form['hello_asso_config[labelChoice]'] = 'Professionnels';
        $form['hello_asso_config[formSlug]'] = 'form-pro';
        $form['hello_asso_config[maxAmount]'] = '500';
        // clientSecret left blank on purpose: must fall back to the source config's secret.

        $this->httpClient->submit($form);
        self::assertResponseRedirects('/admin/clients/' . $client->getId());

        $this->entityManager->clear();
        $refreshedClient = $this->entityManager->getRepository(Client::class)->find($client->getId());
        self::assertCount(2, $refreshedClient->getHelloAssoConfigs());

        $newConfig = null;
        foreach ($refreshedClient->getHelloAssoConfigs() as $candidate) {
            if ($candidate->getFormSlug() === 'form-pro') {
                $newConfig = $candidate;
            }
        }
        self::assertNotNull($newConfig, 'The new form should have been persisted.');
        self::assertSame('Professionnels', $newConfig->getLabel());
        self::assertSame('shared-org', $newConfig->getOrganizationSlug());
        self::assertSame('shared-secret', $this->encryptor->decrypt($newConfig->getClientSecretEncrypted()));
    }

    public function testEditingAFormUpdatesFieldsAndKeepsTheSecretWhenLeftBlank(): void
    {
        $client = $this->createClientWithOneForm();
        $config = $client->getHelloAssoConfigs()->first();
        $this->loginAsAdmin();

        $crawler = $this->httpClient->request('GET', '/admin/clients/' . $client->getId() . '/helloasso/' . $config->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['hello_asso_config[maxAmount]'] = '999';

        $this->httpClient->submit($form);
        self::assertResponseRedirects('/admin/clients/' . $client->getId());

        $this->entityManager->clear();
        $refreshed = $this->entityManager->getRepository(HelloAssoConfig::class)->find($config->getId());
        self::assertSame(999, $refreshed->getMaxAmount());
        self::assertSame('shared-secret', $this->encryptor->decrypt($refreshed->getClientSecretEncrypted()));
    }

    public function testCannotDeactivateTheLastActiveForm(): void
    {
        $client = $this->createClientWithOneForm();
        $config = $client->getHelloAssoConfigs()->first();
        $this->loginAsAdmin();

        $this->submitActionForm(
            $client->getId(),
            '/admin/clients/' . $client->getId() . '/helloasso/' . $config->getId() . '/statut',
        );

        self::assertResponseRedirects('/admin/clients/' . $client->getId());
        $this->httpClient->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Impossible de désactiver');

        $this->entityManager->clear();
        $refreshed = $this->entityManager->getRepository(HelloAssoConfig::class)->find($config->getId());
        self::assertTrue($refreshed->isActive());
    }

    public function testDeactivatingAndReactivatingASecondForm(): void
    {
        $client = $this->createClientWithOneForm();
        $secondConfig = $this->addSecondForm($client);
        $this->loginAsAdmin();

        $this->submitActionForm(
            $client->getId(),
            '/admin/clients/' . $client->getId() . '/helloasso/' . $secondConfig->getId() . '/statut',
        );
        self::assertResponseRedirects('/admin/clients/' . $client->getId());

        $this->entityManager->clear();
        $refreshed = $this->entityManager->getRepository(HelloAssoConfig::class)->find($secondConfig->getId());
        self::assertFalse($refreshed->isActive());

        $this->submitActionForm(
            $client->getId(),
            '/admin/clients/' . $client->getId() . '/helloasso/' . $secondConfig->getId() . '/statut',
        );
        self::assertResponseRedirects('/admin/clients/' . $client->getId());

        $this->entityManager->clear();
        $refreshed = $this->entityManager->getRepository(HelloAssoConfig::class)->find($secondConfig->getId());
        self::assertTrue($refreshed->isActive());
    }

    public function testCannotDeleteAnActiveFormEvenIfUnused(): void
    {
        $client = $this->createClientWithOneForm();
        // The second (non-primary) form specifically — see
        // testCannotDeleteThePrimaryFormEvenOnceDeactivated for why the client's
        // primary form is excluded from this "just deactivate it first" case.
        $config = $this->addSecondForm($client);
        $this->loginAsAdmin();

        // Still active, no payments either way — the "Supprimer" button must not be
        // offered at all; a disabled hint takes its place instead (see
        // testDeletingADeactivatedUnusedFormSucceedsButNeverTheLastOne for the
        // deactivate-then-delete path that does succeed).
        $crawler = $this->httpClient->request('GET', '/admin/clients/' . $client->getId());
        self::assertCount(0, $crawler->filter('form[action="/admin/clients/' . $client->getId() . '/helloasso/' . $config->getId() . '/supprimer"]'));
        self::assertStringContainsString(
            'Désactivez le formulaire avant de pouvoir le supprimer',
            $crawler->filter('.summary-card__footer-action')->last()->html(),
        );
    }

    public function testCannotDeleteThePrimaryFormEvenOnceDeactivated(): void
    {
        $client = $this->createClientWithOneForm();
        $primary = $client->getHelloAssoConfigs()->first();
        $this->addSecondForm($client); // so deactivating the primary doesn't hit the "last active" guard.
        $this->loginAsAdmin();

        $this->submitActionForm(
            $client->getId(),
            '/admin/clients/' . $client->getId() . '/helloasso/' . $primary->getId() . '/statut',
        );

        // No payment history either — it would otherwise qualify for deletion — but
        // it's the client's original form, so the button must never be offered, and
        // the server must refuse it even via a stale tab.
        $crawler = $this->httpClient->request('GET', '/admin/clients/' . $client->getId());
        self::assertCount(0, $crawler->filter('form[action="/admin/clients/' . $client->getId() . '/helloasso/' . $primary->getId() . '/supprimer"]'));
        self::assertStringContainsString(
            "Le formulaire principal d'un client ne peut pas être supprimé",
            $crawler->filter('.summary-card__footer-action')->first()->html(),
        );

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->getRepository(HelloAssoConfig::class)->find($primary->getId()));
    }

    public function testCannotDeleteAFormWithPaymentHistoryEvenOnceDeactivated(): void
    {
        $client = $this->createClientWithOneForm();
        $config = $client->getHelloAssoConfigs()->first();
        $this->addSecondForm($client);
        $config->setActive(false); // isolates the payment-history guard from the "must be inactive" one.

        $payment = new Payment(
            client: $client,
            helloAssoConfig: $config,
            helloAssoPaymentId: 1,
            paymentDate: new \DateTimeImmutable(),
            amount: 10.0,
            payerFirstName: 'Jean',
            payerLastName: 'Dupont',
            email: 'jean@example.com',
        );
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $this->loginAsAdmin();

        // Inactive, but with history — the "Supprimer" button must still not be
        // offered: this guard is permanent, unlike the "must be inactive" one.
        $crawler = $this->httpClient->request('GET', '/admin/clients/' . $client->getId());
        self::assertCount(0, $crawler->filter('form[action="/admin/clients/' . $client->getId() . '/helloasso/' . $config->getId() . '/supprimer"]'));
    }

    public function testDeletingADeactivatedUnusedFormSucceedsButNeverTheLastOne(): void
    {
        $client = $this->createClientWithOneForm();
        $config = $client->getHelloAssoConfigs()->first();
        $secondConfig = $this->addSecondForm($client);
        $secondConfigId = $secondConfig->getId();

        $this->loginAsAdmin();

        // Deactivate the second form first — deleting it while still active is refused
        // (see testCannotDeleteAnActiveFormEvenIfUnused).
        $this->submitActionForm(
            $client->getId(),
            '/admin/clients/' . $client->getId() . '/helloasso/' . $secondConfigId . '/statut',
        );

        $this->submitActionForm(
            $client->getId(),
            '/admin/clients/' . $client->getId() . '/helloasso/' . $secondConfigId . '/supprimer',
        );
        self::assertResponseRedirects('/admin/clients/' . $client->getId());

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(HelloAssoConfig::class)->find($secondConfigId));

        // Only the first form remains, and it's still active (the very last active
        // form can never be deactivated — see testCannotDeactivateTheLastActiveForm),
        // so it can never be deleted either: the two guards compose into "there's
        // always at least one form left" without needing a separate "last one" check.
        $crawler = $this->httpClient->request('GET', '/admin/clients/' . $client->getId());
        self::assertCount(0, $crawler->filter('form[action="/admin/clients/' . $client->getId() . '/helloasso/' . $config->getId() . '/supprimer"]'));

        self::assertNotNull($this->entityManager->getRepository(HelloAssoConfig::class)->find($config->getId()));
    }
}
