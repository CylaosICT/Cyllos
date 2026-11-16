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
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The admin "Page de paiement" form: look, wording and flow of the cyllos-pay page.
 */
class PayPageAdminTest extends WebTestCase
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
        return '/admin/clients/' . $this->client->getId() . '/page-paiement';
    }

    public function testSavesLookWordingAndFlow(): void
    {
        $crawler = $this->httpClient->request('GET', $this->url());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['pay_page[brandPrimaryColor]'] = '#C2410C';
        $form['pay_page[payFont]'] = 'classic';
        $form['pay_page[payRadius]'] = 'round';
        $form['pay_page[payBackground]'] = 'shapes';
        $form['pay_page[payTitle]'] = 'Recharger mon compte';
        $form['pay_page[payButtonLabel]'] = 'Régler {montant}';
        $form['pay_page[payQuickAmounts]'] = '10, 20, 50';
        $confirmStep = $form->get('pay_page[payShowConfirmStep]');
        self::assertInstanceOf(ChoiceFormField::class, $confirmStep);
        $confirmStep->untick();
        $this->httpClient->submit($form);

        self::assertResponseRedirects('/admin/clients/' . $this->client->getId());

        $this->entityManager->clear();
        $config = $this->entityManager->getRepository(StripeConfig::class)->findOneBy([]);
        self::assertInstanceOf(StripeConfig::class, $config);
        self::assertSame('#c2410c', strtolower((string) $config->getBrandPrimaryColor()));
        self::assertSame('classic', $config->getPayFont());
        self::assertSame('round', $config->getPayRadius());
        self::assertSame('shapes', $config->getPayBackground());
        self::assertSame('Recharger mon compte', $config->getPayTitle());
        self::assertSame('Régler {montant}', $config->getPayButtonLabel());
        self::assertSame([10, 20, 50], $config->getPayQuickAmountList());
        self::assertFalse($config->isPayShowConfirmStep());
    }

    public function testRejectsQuickAmountsOutsideTheClientBounds(): void
    {
        $crawler = $this->httpClient->request('GET', $this->url());
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['pay_page[payQuickAmounts]'] = '10, 999';
        $this->httpClient->submit($form);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsAnInvalidColour(): void
    {
        $crawler = $this->httpClient->request('GET', $this->url());
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['pay_page[brandPrimaryColor]'] = 'red; background:url(x)';
        $this->httpClient->submit($form);

        self::assertResponseStatusCodeSame(422);
    }

    public function test404ForAClientWithoutStripe(): void
    {
        $other = (new Client())->setName('HA')->setSlug('ha-client')->setActive(true);
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $this->httpClient->request('GET', '/admin/clients/' . $other->getId() . '/page-paiement');
        self::assertResponseStatusCodeSame(404);
    }
}
