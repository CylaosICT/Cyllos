<?php

namespace App\Payment;

/**
 * Result of CheckoutService::start(): the order reference and the Stripe
 * hosted-page URL to redirect the payer to.
 */
final readonly class CheckoutStart
{
    public function __construct(
        public string $reference,
        public string $redirectUrl,
    ) {
    }
}
