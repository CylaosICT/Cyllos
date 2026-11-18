<?php

namespace App\Tests\Unit\Entity;

use App\Entity\StripeConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * The amount rules of a client's payment page: range, list of amounts, single
 * amount — and the credit that may differ from what is paid.
 */
class StripeConfigAmountRulesTest extends TestCase
{
    private function config(string $mode = StripeConfig::AMOUNT_MODE_RANGE): StripeConfig
    {
        return (new StripeConfig())->setMinAmount(5)->setMaxAmount(250)->setPayAmountMode($mode);
    }

    /** @return list<string> */
    private function violations(StripeConfig $config): array
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $messages = [];
        foreach ($validator->validate($config) as $violation) {
            $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
        }

        return $messages;
    }

    public function testRangeAcceptsAnythingBetweenTheBoundsAndCreditsTheSameAmount(): void
    {
        $config = $this->config();

        self::assertNull($config->offerFor(499));
        self::assertNull($config->offerFor(25001));
        $offer = $config->offerFor(2050);
        self::assertSame(2050, $offer?->paidCents);
        self::assertSame(2050, $offer->creditCents);
        self::assertFalse($offer->hasDifferentCredit());
    }

    public function testRangeCanCreditARateOfTheAmountPaid(): void
    {
        $config = $this->config()->setPayCreditRate(1.5);

        $offer = $config->offerFor(10000);
        self::assertSame(10000, $offer?->paidCents);
        self::assertSame(15000, $offer->creditCents);
        self::assertTrue($offer->hasDifferentCredit());
    }

    public function testListOnlyAllowsTheListedAmountsWithTheirCredit(): void
    {
        $config = $this->config(StripeConfig::AMOUNT_MODE_LIST)->setPayAmountChoices("50, 100:150;\n200:320");

        self::assertSame('50,100:150,200:320', $config->getPayAmountChoices());
        self::assertCount(3, $config->getAmountOffers());
        self::assertSame(5000, $config->offerFor(5000)?->creditCents);
        self::assertSame(15000, $config->offerFor(10000)?->creditCents);
        self::assertSame(32000, $config->offerFor(20000)?->creditCents);
        // Inside the old bounds but not offered: refused.
        self::assertNull($config->offerFor(7500));
        self::assertNull($config->offerFor(100));
        self::assertSame([], $this->violations($config));
    }

    public function testFixedAmountWithAndWithoutADifferentCredit(): void
    {
        $config = $this->config(StripeConfig::AMOUNT_MODE_FIXED)->setPayFixedAmount(100);

        self::assertSame(10000, $config->offerFor(10000)?->creditCents);
        self::assertNull($config->offerFor(9999));

        $config->setPayFixedCredit(150);
        $offer = $config->offerFor(10000);
        self::assertSame(15000, $offer->creditCents);
        self::assertTrue($offer->hasDifferentCredit());
        self::assertSame([], $this->violations($config));
    }

    public function testFixedAmountIsRequiredInFixedMode(): void
    {
        $violations = $this->violations($this->config(StripeConfig::AMOUNT_MODE_FIXED));

        self::assertCount(1, $violations);
        self::assertStringStartsWith('payFixedAmount:', $violations[0]);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function invalidChoices(): iterable
    {
        yield 'empty' => [null];
        yield 'not a number' => ['cinquante'];
        yield 'decimals' => ['12.5'];
        yield 'zero' => ['0'];
        yield 'duplicate paid amount' => ['50, 50:80'];
        yield 'too many' => ['1,2,3,4,5,6,7,8,9,10,11,12,13'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidChoices')]
    public function testInvalidListsAreRejected(?string $choices): void
    {
        $violations = $this->violations($this->config(StripeConfig::AMOUNT_MODE_LIST)->setPayAmountChoices($choices));

        self::assertCount(1, $violations);
        self::assertStringStartsWith('payAmountChoices:', $violations[0]);
    }

    public function testRangeBoundsAreOnlyCheckedInRangeMode(): void
    {
        $config = $this->config(StripeConfig::AMOUNT_MODE_FIXED)->setPayFixedAmount(10)->setMinAmount(300)->setMaxAmount(100);
        self::assertSame([], $this->violations($config));

        $config->setPayAmountMode(StripeConfig::AMOUNT_MODE_RANGE);
        self::assertCount(1, $this->violations($config));
    }

    public function testTheCreditUnitIsTheClientsOwnWordWithOptionalPlural(): void
    {
        $config = $this->config();
        self::assertSame('unités', $config->creditUnitLabel(20000));

        $config->setPayCreditUnit('cigogne|cigognes');
        self::assertSame('cigogne', $config->creditUnitLabel(100));
        self::assertSame('cigognes', $config->creditUnitLabel(20000));
        self::assertSame([], $this->violations($config));

        $config->setPayCreditUnit('point');
        self::assertSame('point', $config->creditUnitLabel(20000));

        foreach (['a|b|c', '|cigognes', str_repeat('x', 21)] as $invalid) {
            self::assertNotSame([], $this->violations($config->setPayCreditUnit($invalid)), $invalid);
        }
    }
}
