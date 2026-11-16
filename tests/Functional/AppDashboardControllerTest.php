<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\EmailAlias;
use App\Entity\HelloAssoConfig;
use App\Entity\Payment;
use App\Entity\PaymentStatus;
use App\Entity\User;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AppDashboardControllerTest extends WebTestCase
{
    private KernelBrowser $httpClient;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->httpClient = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        foreach (['Payment', 'EmailAlias', 'User', 'ClientSetting', 'CyclosConfig', 'CheckoutOrder', 'StripeConfig', 'HelloAssoConfig', 'Client'] as $entity) {
            $this->entityManager->createQuery("DELETE FROM App\\Entity\\$entity")->execute();
        }
    }

    public function testDashboardIsScopedToTheLoggedInClient(): void
    {
        $mine = $this->persistClient('Mine', 'mine');
        $other = $this->persistClient('Other', 'other');

        // 2 credited + 1 failed for my client, all inside the window.
        $this->persistPayment($mine, 100, PaymentStatus::SuccessAuto, 'Alice', 'Mine');
        $this->persistPayment($mine, 101, PaymentStatus::SuccessAuto, 'Bob', 'Mine');
        $this->persistPayment($mine, 102, PaymentStatus::Fail, 'Carol', 'Mine', 'Compte Cyclos introuvable');
        // Noise that must not show up on my dashboard.
        $this->persistPayment($other, 200, PaymentStatus::Fail, 'Zoe', 'Other', 'ne doit pas apparaitre');

        $this->entityManager->persist((new EmailAlias())->setClient($mine)->setSourceEmail('old@a.test')->setTargetEmail('new@a.test'));
        $this->entityManager->flush();

        $this->httpClient->loginUser($this->persistClientUser('me@mine.test', $mine));
        $crawler = $this->httpClient->request('GET', '/app/tableau-de-bord');

        self::assertResponseIsSuccessful();
        $html = $crawler->filter('body')->html();

        self::assertSelectorTextContains('.kpi-grid', '3');            // total in window
        self::assertStringContainsString('Compte Cyclos introuvable', $html); // my failure
        self::assertStringContainsString('old@a.test', $html);         // my e-mail rule
        self::assertStringNotContainsString('ne doit pas apparaitre', $html); // other client's failure
    }

    public function testHomeRedirectsClientToTheDashboard(): void
    {
        $client = $this->persistClient('Redir', 'redir');
        $this->entityManager->flush();

        $this->httpClient->loginUser($this->persistClientUser('r@redir.test', $client));
        $this->httpClient->request('GET', '/');

        self::assertResponseRedirects('/app/tableau-de-bord');
    }

    private function persistClient(string $name, string $slug): Client
    {
        /** @var SecretEncryptor $encryptor */
        $encryptor = self::getContainer()->get(SecretEncryptor::class);

        $client = (new Client())->setName($name)->setSlug($slug)->setActive(true);
        $client->addHelloAssoConfig((new HelloAssoConfig())
            ->setLabel('Particuliers')
            ->setApiUrl('https://api.helloasso.example/')
            ->setHelloAssoClientId('id')
            ->setClientSecretEncrypted($encryptor->encrypt('secret'))
            ->setOrganizationSlug('org')
            ->setFormSlug('form')
            ->setMaxAmount(250)
            ->setFetchNbDays(5));
        $client->setSetting((new ClientSetting())
            ->setPaymentCyclosEnabled(true)
            ->setPaymentAutomaticEnabled(true)
            ->setMailRecipient('ops@example.com'));
        $this->entityManager->persist($client);

        return $client;
    }

    private function persistPayment(Client $client, int $haId, PaymentStatus $status, string $first, string $last, ?string $error = null): void
    {
        $payment = new Payment(
            client: $client,
            helloAssoConfig: $client->getHelloAssoConfigs()->first(),
            helloAssoPaymentId: $haId,
            paymentDate: new \DateTimeImmutable('-1 day'),
            amount: 10.0,
            payerFirstName: $first,
            payerLastName: $last,
            email: strtolower($first) . '@payer.test',
        );
        $payment->setStatus($status);
        if ($error !== null) {
            $payment->setError($error);
        }
        $this->entityManager->persist($payment);
    }

    private function persistClientUser(string $email, Client $client): User
    {
        $user = (new User())->setEmail($email)->setPassword('irrelevant')->setRoles(['ROLE_CLIENT'])->setClient($client);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
