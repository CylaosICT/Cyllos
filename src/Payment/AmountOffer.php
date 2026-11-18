<?php

namespace App\Payment;

/**
 * One way to top up: what the payer pays on Stripe, and what the Cyclos account
 * is credited with. Both are in cents of their own unit; they only differ when
 * the client configured a credit different from the payment (e.g. pay 100 €,
 * receive 150 units).
 */
final readonly class AmountOffer
{
    public function __construct(
        public int $paidCents,
        public int $creditCents,
    ) {
    }

    public function hasDifferentCredit(): bool
    {
        return $this->paidCents !== $this->creditCents;
    }
}
