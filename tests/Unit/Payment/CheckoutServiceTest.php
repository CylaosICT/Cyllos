<?php

namespace App\Tests\Unit\Payment;

use App\Entity\CheckoutOrder;
use App\Entity\CheckoutOrderStatus;
use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\EmailAlias;
use App\Entity\PaymentProvider;
use App\Entity\StripeConfig;
use App\Integration\Cyclos\CyclosClient;
use App\Integration\Cyclos\CyclosUser;
use App\Integration\Stripe\StripeCheckoutSession;
use App\Integration\Stripe\StripeClient;
use App\Integration\Stripe\StripeException;
use App\Payment\CheckoutException;
use App\Payment\CheckoutService;
use App\Repository\EmailAliasRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CheckoutServiceTest extends TestCase
{
    private EmailAliasRepository&MockObject $aliases;
    private CyclosClient&MockObject $cyclos;
    private StripeClient&MockObject $stripe;
    private EntityManagerInterface&MockObject $entityManager;

    protected function setUp(): void
    {
        $this->aliases = $this->createMock(EmailAliasRepository::class);
        $this->cyclos = $this->createMock(CyclosClient::class);
        $this->stripe = $this->createMock(StripeClient::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    private function service(string $baseUrl = 'https://pay.example/'): CheckoutService
    {
        return new CheckoutService($this->entityManager, $this->aliases, $this->cyclos, $this->stripe, new NullLogger(), $baseUrl);
    }

    private function client(bool $active = true, PaymentProvider $provider = PaymentProvider::Stripe): Client
    {
        $client = (new Client())->setName('Le RolloN')->setSlug('rollon')->setActive($active)->setPaymentProvider($provider);
        $client->setStripeConfig((new StripeConfig())->setMinAmount(5)->setMaxAmount(250));
        $client->setCyclosConfig((new CyclosConfig())->setBaseUrl('https://cyclos.example/api/'));
        $client->setSetting((new ClientSetting())->setMailRecipient('ops@example.com'));

        return $client;
    }

    private function expectFailure(string $reason, callable $call): void
    {
        try {
            $call();
            self::fail('Expected a CheckoutException (' . $reason . ').');
        } catch (CheckoutException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testStartsACheckoutAndReturnsTheStripeUrl(): void
    {
        $client = $this->client();
        $user = new CyclosUser('42', 'part');
        $this->cyclos->expects(self::once())->method('findUserByEmail')->with($client->getCyclosConfig(), 'payer@example.com')->willReturn($user);
        $this->cyclos->method('resolveEmissionType')->willReturn('emission.Part');

        $this->stripe->expects(self::once())->method('createCheckoutSession')->willReturnCallback(
            function (StripeConfig $config, CheckoutOrder $order, string $name, string $success, string $cancel): StripeCheckoutSession {
                self::assertSame(2000, $order->getAmountCents());
                self::assertSame('payer@example.com', $order->getEmail());
                self::assertSame('https://pay.example/rollon/retour/' . $order->getReference(), $success);
                self::assertSame('https://pay.example/rollon?annule=' . $order->getReference(), $cancel);

                return new StripeCheckoutSession('cs_test_1', 'https://checkout.stripe.com/c/pay/cs_test_1');
            },
        );

        $start = $this->service()->start($client, ' payer@example.com ', 2000);

        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $start->redirectUrl);
        self::assertStringStartsWith('S-', $start->reference);
    }

    public function testRefusesAnUnavailableClient(): void
    {
        $this->cyclos->expects(self::never())->method('findUserByEmail');

        $this->expectFailure(CheckoutException::CLIENT_UNAVAILABLE, fn () => $this->service()->start($this->client(active: false), 'a@b.fr', 2000));
        $this->expectFailure(CheckoutException::CLIENT_UNAVAILABLE, fn () => $this->service()->start($this->client(provider: PaymentProvider::HelloAsso), 'a@b.fr', 2000));
        $this->expectFailure(CheckoutException::CLIENT_UNAVAILABLE, fn () => $this->service('')->start($this->client(), 'a@b.fr', 2000));

        $inactiveStripe = $this->client();
        $inactiveStripe->getStripeConfig()->setActive(false);
        $this->expectFailure(CheckoutException::CLIENT_UNAVAILABLE, fn () => $this->service()->start($inactiveStripe, 'a@b.fr', 2000));
    }

    public function testRefusesAnInvalidEmail(): void
    {
        foreach (['', 'not-an-email', 'a@', str_repeat('a', 250) . '@b.fr'] as $email) {
            $this->expectFailure(CheckoutException::INVALID_EMAIL, fn () => $this->service()->start($this->client(), $email, 2000));
        }
    }

    public function testEnforcesTheAmountBoundsInCents(): void
    {
        $this->cyclos->method('findUserByEmail')->willReturn(new CyclosUser('42', 'part'));
        $this->cyclos->method('resolveEmissionType')->willReturn('emission.Part');
        $this->stripe->method('createCheckoutSession')->willReturn(new StripeCheckoutSession('cs', 'https://x'));

        foreach ([0, -100, 499, 25001, 100000] as $cents) {
            $this->expectFailure(CheckoutException::INVALID_AMOUNT, fn () => $this->service()->start($this->client(), 'a@b.fr', $cents));
        }

        // Bounds are inclusive: 5 € and 250 €.
        self::assertStringStartsWith('S-', $this->service()->start($this->client(), 'a@b.fr', 500)->reference);
        self::assertStringStartsWith('S-', $this->service()->start($this->client(), 'a@b.fr', 25000)->reference);
    }

    public function testAListedAmountCreditsWhatTheClientConfiguredAndIsAnnouncedOnStripe(): void
    {
        $client = $this->client();
        $client->getStripeConfig()->setPayAmountMode(StripeConfig::AMOUNT_MODE_LIST)->setPayAmountChoices('50, 100:150')->setPayCreditUnit('points');
        $this->cyclos->method('findUserByEmail')->willReturn(new CyclosUser('42', 'part'));
        $this->cyclos->method('resolveEmissionType')->willReturn('emission.Part');

        $this->stripe->expects(self::once())->method('createCheckoutSession')->willReturnCallback(
            function (StripeConfig $config, CheckoutOrder $order, string $name): StripeCheckoutSession {
                self::assertSame(10000, $order->getAmountCents());
                self::assertSame(15000, $order->getCreditAmountCents());
                self::assertStringContainsString('150 points', $name);

                return new StripeCheckoutSession('cs_test_2', 'https://checkout.stripe.com/c/pay/cs_test_2');
            },
        );

        $this->service()->start($client, 'a@b.fr', 10000);
    }

    public function testAnAmountOutsideTheClientsListIsRefusedEvenInsideTheRange(): void
    {
        $client = $this->client();
        $client->getStripeConfig()->setPayAmountMode(StripeConfig::AMOUNT_MODE_LIST)->setPayAmountChoices('50, 100');
        $this->stripe->expects(self::never())->method('createCheckoutSession');

        $this->expectFailure(CheckoutException::INVALID_AMOUNT, fn () => $this->service()->start($client, 'a@b.fr', 7500));
    }

    public function testFixedAmountOnlyAcceptsTheConfiguredPayment(): void
    {
        $client = $this->client();
        $client->getStripeConfig()->setPayAmountMode(StripeConfig::AMOUNT_MODE_FIXED)->setPayFixedAmount(100);
        $this->stripe->expects(self::never())->method('createCheckoutSession');

        $this->expectFailure(CheckoutException::INVALID_AMOUNT, fn () => $this->service()->start($client, 'a@b.fr', 5000));
    }

    public function testRefusesWhenTheCyclosAccountDoesNotExistAndNeverCallsStripe(): void
    {
        $this->cyclos->method('findUserByEmail')->willReturn(null);
        $this->stripe->expects(self::never())->method('createCheckoutSession');
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectFailure(CheckoutException::ACCOUNT_NOT_FOUND, fn () => $this->service()->start($this->client(), 'ghost@example.com', 2000));
    }

    public function testRefusesAnAccountWhoseGroupCannotBeCredited(): void
    {
        $this->cyclos->method('findUserByEmail')->willReturn(new CyclosUser('42', 'other-group'));
        $this->cyclos->method('resolveEmissionType')->willReturn(null);
        $this->stripe->expects(self::never())->method('createCheckoutSession');

        $this->expectFailure(CheckoutException::ACCOUNT_NOT_ELIGIBLE, fn () => $this->service()->start($this->client(), 'a@b.fr', 2000));
    }

    public function testEmailCorrectionRuleIsAppliedToTheCyclosLookup(): void
    {
        $client = $this->client();
        $alias = (new EmailAlias())->setClient($client)->setSourceEmail('typed@example.com')->setTargetEmail('real@example.com');
        $this->aliases->expects(self::once())->method('findOneByClientAndSourceEmail')->with($client, 'typed@example.com')->willReturn($alias);

        $this->cyclos->expects(self::once())->method('findUserByEmail')->with($client->getCyclosConfig(), 'real@example.com')->willReturn(new CyclosUser('42', 'part'));
        $this->cyclos->method('resolveEmissionType')->willReturn('emission.Part');
        $this->stripe->method('createCheckoutSession')->willReturn(new StripeCheckoutSession('cs', 'https://x'));

        $this->service()->start($client, 'typed@example.com', 2000);
    }

    public function testStripeFailureMarksTheOrderFailedAndSurfacesAProviderError(): void
    {
        $this->cyclos->method('findUserByEmail')->willReturn(new CyclosUser('42', 'part'));
        $this->cyclos->method('resolveEmissionType')->willReturn('emission.Part');
        $this->stripe->method('createCheckoutSession')->willThrowException(new StripeException('boom'));

        $persisted = null;
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted = $entity;
        });

        $this->expectFailure(CheckoutException::PROVIDER_ERROR, fn () => $this->service()->start($this->client(), 'a@b.fr', 2000));

        self::assertInstanceOf(CheckoutOrder::class, $persisted);
        self::assertSame(CheckoutOrderStatus::Failed, $persisted->getStatus());
    }
    public function testCheckAccountTellsProfessionalFromIndividual(): void
    {
        $client = $this->client();
        $client->getCyclosConfig()->setGroupProInternal('pro, pro2');
        $this->cyclos->method('resolveEmissionType')->willReturn('emission');

        $this->cyclos->expects(self::exactly(2))->method('findUserByEmail')->willReturnOnConsecutiveCalls(new CyclosUser('1', 'pro2'), new CyclosUser('2', 'part'));

        self::assertSame('professional', $this->service()->checkAccount($client, 'a@b.fr'));
        self::assertSame('individual', $this->service()->checkAccount($client, 'c@d.fr'));
    }

    public function testCheckAccountRefusesUnknownOrIneligibleAccountsWithoutCreatingAnything(): void
    {
        $this->entityManager->expects(self::never())->method('persist');
        $this->stripe->expects(self::never())->method('createCheckoutSession');

        $this->cyclos->method('findUserByEmail')->willReturnOnConsecutiveCalls(null, new CyclosUser('2', 'other'));
        $this->cyclos->method('resolveEmissionType')->willReturn(null);

        $this->expectFailure(CheckoutException::ACCOUNT_NOT_FOUND, fn () => $this->service()->checkAccount($this->client(), 'ghost@example.com'));
        $this->expectFailure(CheckoutException::ACCOUNT_NOT_ELIGIBLE, fn () => $this->service()->checkAccount($this->client(), 'a@b.fr'));
        $this->expectFailure(CheckoutException::INVALID_EMAIL, fn () => $this->service()->checkAccount($this->client(), 'nope'));
        $this->expectFailure(CheckoutException::CLIENT_UNAVAILABLE, fn () => $this->service()->checkAccount($this->client(active: false), 'a@b.fr'));
    }
}
