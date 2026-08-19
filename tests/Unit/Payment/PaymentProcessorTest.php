<?php

namespace App\Tests\Unit\Payment;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\EmailAlias;
use App\Entity\HelloAssoConfig;
use App\Entity\Payment;
use App\Entity\PaymentStatus;
use App\Integration\Cyclos\CyclosClient;
use App\Integration\HelloAsso\HelloAssoClient;
use App\Integration\HelloAsso\HelloAssoFetchedPayment;
use App\Notification\NotificationMailer;
use App\Payment\PaymentProcessor;
use App\Repository\EmailAliasRepository;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Covers the manual-sync auto-credit behaviour: PaymentProcessor::fetchMissingPayments()
 * must only attempt a Cyclos credit per fetched payment when explicitly asked to
 * (the manual "Synchro Hello Asso" button), never for the periodic safety-net command,
 * and must still respect the client's own automatic/amount-limit rules either way.
 */
class PaymentProcessorTest extends TestCase
{
    private function makeClient(bool $automatic, int $maxAmount = 250): Client
    {
        $client = (new Client())->setName('Test')->setSlug('test')->setActive(true);

        $haConfig = (new HelloAssoConfig())
            ->setApiUrl('https://api.helloasso.example/')
            ->setHelloAssoClientId('id')
            ->setClientSecretEncrypted('enc')
            ->setOrganizationSlug('org')
            ->setFormSlug('form')
            ->setMaxAmount($maxAmount)
            ->setFetchNbDays(5);
        $client->setHelloAssoConfig($haConfig);

        $cyclosConfig = (new CyclosConfig())
            ->setBaseUrl('https://cyclos.example/api/')
            ->setTechnicalUserId('1')
            ->setPasswordEncrypted('enc')
            ->setGroupProInternal('pro')
            ->setGroupsPartInternal('part')
            ->setEmissionProInternal('emission.Pro')
            ->setEmissionPartInternal('emission.Part');
        $client->setCyclosConfig($cyclosConfig);

        $setting = (new ClientSetting())
            ->setPaymentCyclosEnabled(true)
            ->setPaymentAutomaticEnabled($automatic)
            ->setMailRecipient('ops@example.com');
        $client->setSetting($setting);

        return $client;
    }

    private function makeProcessor(
        HelloAssoClient $helloAssoClient,
        CyclosClient $cyclosClient,
        NotificationMailer $mailer,
        ?EmailAliasRepository $emailAliasRepository = null,
    ): PaymentProcessor {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $paymentRepository = $this->createStub(PaymentRepository::class);
        $paymentRepository->method('findAllHelloAssoIdsForClient')->willReturn([]);

        if ($emailAliasRepository === null) {
            $emailAliasRepository = $this->createStub(EmailAliasRepository::class);
            $emailAliasRepository->method('findOneByClientAndSourceEmail')->willReturn(null);
        }

        return new PaymentProcessor(
            $entityManager,
            $paymentRepository,
            $emailAliasRepository,
            $helloAssoClient,
            $cyclosClient,
            $mailer,
            new NullLogger(),
        );
    }

    public function testPeriodicFetchNeverAttemptsCreditEvenWithAutomaticEnabled(): void
    {
        $client = $this->makeClient(automatic: true);

        $helloAssoClient = $this->createStub(HelloAssoClient::class);
        $helloAssoClient->method('fetchPaymentsHistory')->willReturn([
            new HelloAssoFetchedPayment(111, 2000, '2026-08-19T10:00:00+02:00', 'Jean', 'Dupont', 'jean@example.com'),
        ]);

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::never())->method('findUserByEmail');

        $mailer = $this->createStub(NotificationMailer::class);

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer);

        $added = $processor->fetchMissingPayments($client);

        self::assertSame(1, $added);
    }

    public function testManualSyncSkipsCreditWhenAutomaticDisabled(): void
    {
        $client = $this->makeClient(automatic: false);

        $helloAssoClient = $this->createStub(HelloAssoClient::class);
        $helloAssoClient->method('fetchPaymentsHistory')->willReturn([
            new HelloAssoFetchedPayment(222, 2000, '2026-08-19T10:00:00+02:00', 'Jean', 'Dupont', 'jean@example.com'),
        ]);

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::never())->method('findUserByEmail');

        $mailer = $this->createStub(NotificationMailer::class);

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer);

        $added = $processor->fetchMissingPayments($client, attemptAutomaticCredit: true);

        self::assertSame(1, $added);
    }

    public function testManualSyncMarksOverLimitPaymentAsTooHighWithoutAttemptingCredit(): void
    {
        $client = $this->makeClient(automatic: true, maxAmount: 10);

        $helloAssoClient = $this->createStub(HelloAssoClient::class);
        $helloAssoClient->method('fetchPaymentsHistory')->willReturn([
            new HelloAssoFetchedPayment(333, 100000, '2026-08-19T10:00:00+02:00', 'Jean', 'Dupont', 'jean@example.com'),
        ]);

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::never())->method('findUserByEmail');

        $mailer = $this->createMock(NotificationMailer::class);
        $mailer->expects(self::once())->method('send')
            ->with('ops@example.com', self::stringContains('limite'), self::anything());

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer);

        $added = $processor->fetchMissingPayments($client, attemptAutomaticCredit: true);

        self::assertSame(1, $added);
    }

    public function testManualSyncAttemptsCreditForEachEligibleFetchedPayment(): void
    {
        $client = $this->makeClient(automatic: true);

        $helloAssoClient = $this->createStub(HelloAssoClient::class);
        $helloAssoClient->method('fetchPaymentsHistory')->willReturn([
            new HelloAssoFetchedPayment(444, 2000, '2026-08-19T10:00:00+02:00', 'Jean', 'Dupont', 'jean@example.com'),
            new HelloAssoFetchedPayment(555, 3000, '2026-08-19T11:00:00+02:00', 'Marie', 'Curie', 'marie@example.com'),
        ]);
        $helloAssoClient->method('getAlternativeEmail')->willReturn(null);

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::exactly(2))->method('findUserByEmail')
            ->willReturn(null); // simulate "user not found" so the flow stops there without needing to fake the rest of the credit pipeline.

        $mailer = $this->createStub(NotificationMailer::class);

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer);

        $added = $processor->fetchMissingPayments($client, attemptAutomaticCredit: true);

        self::assertSame(2, $added);
    }

    public function testCreditUsesTheAliasedEmailInsteadOfThePayerEmailWhenARuleExists(): void
    {
        $client = $this->makeClient(automatic: true);

        $payment = new Payment(
            client: $client,
            helloAssoPaymentId: 666,
            paymentDate: new \DateTimeImmutable(),
            amount: 20.0,
            payerFirstName: 'Jean',
            payerLastName: 'Dupont',
            email: 'jean.helloasso@example.com',
        );

        $alias = (new EmailAlias())->setClient($client)
            ->setSourceEmail('jean.helloasso@example.com')
            ->setTargetEmail('jean.real-cyclos-account@example.com');

        $emailAliasRepository = $this->createStub(EmailAliasRepository::class);
        $emailAliasRepository->method('findOneByClientAndSourceEmail')->willReturn($alias);

        $helloAssoClient = $this->createStub(HelloAssoClient::class);

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::once())->method('findUserByEmail')
            ->with(self::anything(), 'jean.real-cyclos-account@example.com')
            ->willReturn(null); // stop here — the assertion above is what this test cares about.

        $mailer = $this->createStub(NotificationMailer::class);

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer, $emailAliasRepository);
        $processor->creditManually($payment);
    }

    public function testAliasLookupUsesTheImmutablePayerEmailEvenIfEmailWasAlreadyOverwritten(): void
    {
        $client = $this->makeClient(automatic: true);

        $payment = new Payment(
            client: $client,
            helloAssoPaymentId: 777,
            paymentDate: new \DateTimeImmutable(),
            amount: 20.0,
            payerFirstName: 'Marie',
            payerLastName: 'Curie',
            email: 'marie.helloasso@example.com',
        );
        // Simulate a prior successful credit having already overwritten `email`
        // (e.g. via the HelloAsso alternative-email fallback) — payerEmail must
        // stay untouched and keep being the lookup key for the alias rule.
        $payment->setEmail('marie.alternative@example.com');

        $emailAliasRepository = $this->createMock(EmailAliasRepository::class);
        $emailAliasRepository->expects(self::once())->method('findOneByClientAndSourceEmail')
            ->with($client, 'marie.helloasso@example.com')
            ->willReturn(null);

        $helloAssoClient = $this->createStub(HelloAssoClient::class);
        $helloAssoClient->method('getAlternativeEmail')->willReturn(null);

        $cyclosClient = $this->createStub(CyclosClient::class);
        $cyclosClient->method('findUserByEmail')->willReturn(null);

        $mailer = $this->createStub(NotificationMailer::class);

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer, $emailAliasRepository);
        $processor->creditManually($payment);
    }
}
