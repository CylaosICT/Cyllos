<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\HelloAssoConfig;
use App\Entity\Payment;
use App\Entity\PaymentStatus;
use App\Entity\User;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardControllerTest extends WebTestCase
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

    private function loginAs(array $roles): void
    {
        $user = (new User())
            ->setEmail('u-' . uniqid() . '@dash.example')
            ->setPassword('irrelevant')
            ->setRoles($roles);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->httpClient->loginUser($user);
    }

    public function testDashboardIsForbiddenForANonAdmin(): void
    {
        $this->loginAs(['ROLE_CLIENT']);
        $this->httpClient->request('GET', '/admin/tableau-de-bord');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDashboardShowsPaymentCountsForAnAdmin(): void
    {
        $client = $this->persistFullClient();

        foreach ([PaymentStatus::SuccessAuto, PaymentStatus::SuccessAuto, PaymentStatus::Fail] as $i => $status) {
            $payment = new Payment(
                client: $client,
                helloAssoConfig: $client->getHelloAssoConfigs()->first(),
                helloAssoPaymentId: 1000 + $i,
                paymentDate: new \DateTimeImmutable('-1 day'),
                amount: 10.0,
                payerFirstName: 'A',
                payerLastName: 'B',
                email: 'a@b.example',
            );
            $payment->setStatus($status);
            if ($status === PaymentStatus::Fail) {
                $payment->setError('Erreur de test Cyclos');
            }
            $this->entityManager->persist($payment);
        }
        $this->entityManager->flush();

        $this->loginAs(['ROLE_ADMIN']);
        $crawler = $this->httpClient->request('GET', '/admin/tableau-de-bord');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kpi-grid', '3'); // 3 payments in window
        self::assertStringContainsString('Erreur de test Cyclos', $crawler->filter('body')->html());

        // Money figure (2 credited payments of 10.00) and the per-client table.
        $html = $crawler->filter('body')->html();
        self::assertStringContainsString('20,00 €', $html);
        self::assertStringContainsString('Détail par client', $html);
        self::assertSelectorTextContains('details', 'Dash Co');
    }

    private function persistFullClient(): Client
    {
        /** @var SecretEncryptor $encryptor */
        $encryptor = self::getContainer()->get(SecretEncryptor::class);

        $client = (new Client())->setName('Dash Co')->setSlug('dash-co')->setActive(true);
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
