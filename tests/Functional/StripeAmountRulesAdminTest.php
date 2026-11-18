<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\PaymentProvider;
use App\Entity\StripeConfig;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The "Type de règlement" part of the admin Stripe form: range, list of amounts or single amount, with its credit.
 */
class StripeAmountRulesAdminTest extends WebTestCase
{
    private KernelBrowser $httpClient;
    private EntityManagerInterface $entityManager;
    private Client $client;

    protected function setUp(): void
    {
        $this->httpClient = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        foreach (['Payment', 'EmailAlias', 'User', 'ClientSetting', 'CyclosConfig', 'CheckoutOrder', 'StripeConfig', 'HelloAssoConfig', 'Client'] as $entity) {
            $this->entityManager->createQuery("DELETE FROM App\\Entity\\$entity")->execute();
        }

        $this->client = (new Client())->setName('Stripe Client')->setSlug('stripe-client')->setActive(true)->setPaymentProvider(PaymentProvider::Stripe);
        $this->client->setStripeConfig((new StripeConfig())->setMinAmount(5)->setMaxAmount(250));
        $this->client->setCyclosConfig((new CyclosConfig())->setBaseUrl('https://cyclos.example/api/')->setTechnicalUserId('1'));
        $this->client->setSetting((new ClientSetting())->setMailRecipient('ops@example.com'));
        $this->entityManager->persist($this->client);

        $admin = (new User())->setEmail('a-' . uniqid() . '@e.test')->setPassword('x')->setRoles(['ROLE_ADMIN']);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->httpClient->loginUser($admin);
    }

    private function url(): string
    {
        return '/admin/clients/' . $this->client->getId() . '/stripe';
    }

    public function testRejectsQuickAmountsOutsideTheClientBounds(): void
    {
        $crawler = $this->httpClient->request('GET', $this->url());
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['stripe_config[payQuickAmounts]'] = '10, 999';
        $this->httpClient->submit($form);

        self::assertResponseStatusCodeSame(422);
    }

    public function testSavesAListOfAmountsWithADifferentCredit(): void
    {
        $crawler = $this->httpClient->request('GET', $this->url());
        self::assertSelectorExists('[data-controller="pay-amount"]');
        self::assertCount(3, $crawler->filter('input[name="stripe_config[payAmountMode]"]'));

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['stripe_config[payAmountMode]'] = 'list';
        $form['stripe_config[payAmountChoices]'] = '50, 100:150 ; 200:320';
        $form['stripe_config[payCreditUnit]'] = 'points';
        $this->httpClient->submit($form);

        self::assertResponseRedirects('/admin/clients/' . $this->client->getId());
        $this->entityManager->clear();
        $config = $this->entityManager->getRepository(StripeConfig::class)->findOneBy([]);
        self::assertInstanceOf(StripeConfig::class, $config);
        self::assertSame('list', $config->getPayAmountMode());
        self::assertSame('50,100:150,200:320', $config->getPayAmountChoices());
        self::assertSame('points', $config->getPayCreditUnit());
        self::assertSame(15000, $config->offerFor(10000)?->creditCents);
        self::assertNull($config->offerFor(7500));

        $this->httpClient->request('GET', '/admin/clients/' . $this->client->getId());
        self::assertSelectorTextContains('body', '100 € → 150 points');
    }

    public function testSavesASingleAmountWithADifferentCredit(): void
    {
        $crawler = $this->httpClient->request('GET', $this->url());
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['stripe_config[payAmountMode]'] = 'fixed';
        $form['stripe_config[payFixedAmount]'] = '100';
        $form['stripe_config[payFixedCredit]'] = '150';
        $this->httpClient->submit($form);

        self::assertResponseRedirects('/admin/clients/' . $this->client->getId());
        $this->entityManager->clear();
        $config = $this->entityManager->getRepository(StripeConfig::class)->findOneBy([]);
        self::assertInstanceOf(StripeConfig::class, $config);
        self::assertSame(15000, $config->offerFor(10000)?->creditCents);
    }

    public function testRejectsAnIncompleteAmountConfiguration(): void
    {
        $crawler = $this->httpClient->request('GET', $this->url());
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['stripe_config[payAmountMode]'] = 'list';
        $form['stripe_config[payAmountChoices]'] = '';
        $this->httpClient->submit($form);
        self::assertResponseStatusCodeSame(422);

        $crawler = $this->httpClient->request('GET', $this->url());
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['stripe_config[payAmountMode]'] = 'fixed';
        $this->httpClient->submit($form);
        self::assertResponseStatusCodeSame(422);
    }

    public function testQuickAmountsMustStayInsideTheRange(): void
    {
        $crawler = $this->httpClient->request('GET', $this->url());
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['stripe_config[payQuickAmounts]'] = '10, 999';
        $this->httpClient->submit($form);

        self::assertResponseStatusCodeSame(422);
    }

    public function testQuickAmountsAreSaved(): void
    {
        $crawler = $this->httpClient->request('GET', $this->url());
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['stripe_config[payQuickAmounts]'] = '10, 20, 50';
        $this->httpClient->submit($form);

        self::assertResponseRedirects('/admin/clients/' . $this->client->getId());
        $this->entityManager->clear();
        $config = $this->entityManager->getRepository(StripeConfig::class)->findOneBy([]);
        self::assertSame([10, 20, 50], $config?->getPayQuickAmountList());
    }
}
