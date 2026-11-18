<?php

namespace App\Tests\Unit\Entity;

use App\Entity\CheckoutOrder;
use App\Entity\Client;
use App\Entity\Payment;
use PHPUnit\Framework\TestCase;

class PaymentCreditAmountTest extends TestCase
{
    public function testAStripePaymentCreditsTheAmountOfItsOrder(): void
    {
        $order = new CheckoutOrder(new Client(), 'a@b.fr', 10000, 15000);
        $payment = Payment::forStripeOrder($order, new \DateTimeImmutable(), 'Payeur');

        self::assertSame(100.0, $payment->getAmount());
        self::assertSame(150.0, $payment->getCreditAmount());
        self::assertTrue($payment->hasDifferentCredit());
    }

    public function testWithoutADifferentCreditTheCreditIsThePayment(): void
    {
        $payment = Payment::forStripeOrder(new CheckoutOrder(new Client(), 'a@b.fr', 2050), new \DateTimeImmutable(), '');

        self::assertSame(20.5, $payment->getCreditAmount());
        self::assertFalse($payment->hasDifferentCredit());
    }
}
