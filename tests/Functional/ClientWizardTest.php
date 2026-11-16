<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\PaymentProvider;
use App\Entity\User;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Walks the 5-step "new client" wizard exactly as a browser would — crawling
 * each step's real form and submitting it with its own embedded CSRF token,
 * never a fabricated one. This is what caught the "Réglages" step missing
 * its CSRF field entirely (`form_end(form, {render_rest: false})` without a
 * matching `form_rest(form)` call): the classic session CSRF manager rejects
 * an absent token, whereas the previously-configured stateless CSRF manager
 * silently accepted the request on Origin/Referer alone. See
 * /dev/documentation, section "Incidents résolus".
 */
class ClientWizardTest extends WebTestCase
{
    /** A made-up live-mode key, assembled so secret scanners don't mistake it for a real one. */
    private const FAKE_LIVE_KEY = 'rk_' . 'live_' . 'abcdefghijklmnopqrstuvwx1234';

    private KernelBrowser $httpClient;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->httpClient = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function loginAsAdmin(): void
    {
        $admin = new User();
        $admin->setEmail('admin-' . uniqid() . '@wizard.example')->setPassword('irrelevant')->setRoles(['ROLE_ADMIN']);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        $this->httpClient->loginUser($admin);
    }

    public function testCompletingAllFiveStepsCreatesTheClient(): void
    {
        $this->loginAsAdmin();
        $slug = 'wizard-e2e-' . uniqid();

        $crawler = $this->httpClient->request('GET', '/admin/clients/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Continuer')->form([
            'client_info[name]' => 'Wizard E2E',
            'client_info[slug]' => $slug,
        ]);
        $crawler = $this->httpClient->submit($form);
        self::assertResponseRedirects('/admin/clients/new/prestataire');
        $crawler = $this->httpClient->followRedirect();

        $crawler = $this->httpClient->submit($crawler->selectButton('HelloAsso')->form());
        self::assertResponseRedirects('/admin/clients/new/helloasso');
        $crawler = $this->httpClient->followRedirect();

        $form = $crawler->selectButton('Continuer')->form([
            'hello_asso_config[apiUrl]' => 'https://api.helloasso.com/',
            'hello_asso_config[helloAssoClientId]' => 'client-id',
            'hello_asso_config[clientSecret]' => 'secret',
            'hello_asso_config[organizationSlug]' => 'org-slug',
            'hello_asso_config[formSlug]' => 'form-slug',
            'hello_asso_config[maxAmount]' => '250',
            'hello_asso_config[fetchNbDays]' => '5',
        ]);
        $crawler = $this->httpClient->submit($form);
        self::assertResponseRedirects('/admin/clients/new/cyclos');
        $crawler = $this->httpClient->followRedirect();

        $form = $crawler->selectButton('Continuer')->form([
            'cyclos_config[baseUrl]' => 'https://cyclos.example/api/',
            'cyclos_config[technicalUserId]' => '1',
            'cyclos_config[password]' => 'pwd',
            'cyclos_config[groupProInternal]' => 'pro',
            'cyclos_config[groupsPartInternal]' => 'part',
            'cyclos_config[emissionProInternal]' => 'emission.Pro',
            'cyclos_config[emissionPartInternal]' => 'emission.Part',
        ]);
        $crawler = $this->httpClient->submit($form);
        self::assertResponseRedirects('/admin/clients/new/reglages');
        $crawler = $this->httpClient->followRedirect();

        $form = $crawler->selectButton('Créer le client')->form([
            'client_setting[mailRecipient]' => 'ops@wizard-e2e.example',
        ]);
        $this->httpClient->submit($form);
        self::assertResponseRedirects();

        $client = $this->entityManager->getRepository(Client::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($client, 'The client should have been persisted at the end of the wizard.');
        self::assertNotNull($client->getSetting());
        self::assertSame('ops@wizard-e2e.example', $client->getSetting()->getMailRecipient());
        self::assertSame(PaymentProvider::HelloAsso, $client->getPaymentProvider());
        self::assertCount(1, $client->getHelloAssoConfigs());
        self::assertNull($client->getStripeConfig());
    }

    public function testChoosingStripeCreatesAStripeClientWithEncryptedSecrets(): void
    {
        $this->loginAsAdmin();
        $slug = 'wizard-stripe-' . uniqid();

        $crawler = $this->httpClient->request('GET', '/admin/clients/new');
        $this->httpClient->submit($crawler->selectButton('Continuer')->form([
            'client_info[name]' => 'Wizard Stripe',
            'client_info[slug]' => $slug,
        ]));
        self::assertResponseRedirects('/admin/clients/new/prestataire');
        $crawler = $this->httpClient->followRedirect();

        $this->httpClient->submit($crawler->selectButton('Stripe')->form());
        self::assertResponseRedirects('/admin/clients/new/stripe');
        $crawler = $this->httpClient->followRedirect();

        $this->httpClient->submit($crawler->selectButton('Continuer')->form([
            'stripe_config[secretKey]' => self::FAKE_LIVE_KEY,
            'stripe_config[webhookSecret]' => 'whsec_abcdefghijklmnopqrstuvwx',
            'stripe_config[minAmount]' => '5',
            'stripe_config[maxAmount]' => '300',
        ]));
        self::assertResponseRedirects('/admin/clients/new/cyclos');
        $crawler = $this->httpClient->followRedirect();

        $this->httpClient->submit($crawler->selectButton('Continuer')->form([
            'cyclos_config[baseUrl]' => 'https://cyclos.example/api/',
            'cyclos_config[technicalUserId]' => '1',
            'cyclos_config[password]' => 'pwd',
            'cyclos_config[groupProInternal]' => 'pro',
            'cyclos_config[groupsPartInternal]' => 'part',
            'cyclos_config[emissionProInternal]' => 'emission.Pro',
            'cyclos_config[emissionPartInternal]' => 'emission.Part',
        ]));
        $crawler = $this->httpClient->followRedirect();

        $this->httpClient->submit($crawler->selectButton('Créer le client')->form([
            'client_setting[mailRecipient]' => 'ops@wizard-stripe.example',
        ]));
        self::assertResponseRedirects();

        $client = $this->entityManager->getRepository(Client::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($client);
        self::assertSame(PaymentProvider::Stripe, $client->getPaymentProvider());
        self::assertCount(0, $client->getHelloAssoConfigs());

        $stripe = $client->getStripeConfig();
        self::assertNotNull($stripe);
        self::assertTrue($stripe->isLiveMode());
        self::assertSame('rk_live_…1234', $stripe->getSecretKeyHint());
        self::assertSame(300, $stripe->getMaxAmount());

        $encryptor = self::getContainer()->get(SecretEncryptor::class);
        self::assertNotSame(self::FAKE_LIVE_KEY, $stripe->getSecretKeyEncrypted());
        self::assertSame(self::FAKE_LIVE_KEY, $encryptor->decrypt($stripe->getSecretKeyEncrypted()));
        self::assertSame('whsec_abcdefghijklmnopqrstuvwx', $encryptor->decrypt($stripe->getWebhookSecretEncrypted()));

        $this->httpClient->request('GET', '/admin/clients/' . $client->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'rk_live_…1234');
        self::assertSelectorTextNotContains('body', 'abcdefghijklmnopqrstuvwx1234');
    }

    public function testProviderStepIsRequiredBeforeTheProviderConfiguration(): void
    {
        $this->loginAsAdmin();

        $this->httpClient->request('GET', '/admin/clients/new/stripe');
        self::assertResponseRedirects('/admin/clients/new');

        $crawler = $this->httpClient->request('GET', '/admin/clients/new');
        $this->httpClient->submit($crawler->selectButton('Continuer')->form([
            'client_info[name]' => 'Sans choix',
            'client_info[slug]' => 'sans-choix-' . uniqid(),
        ]));

        $this->httpClient->request('GET', '/admin/clients/new/helloasso');
        self::assertResponseRedirects('/admin/clients/new/prestataire');
        $this->httpClient->request('GET', '/admin/clients/new/cyclos');
        self::assertResponseRedirects('/admin/clients/new');
    }
}
