<?php

namespace App\Integration\Stripe;

/**
 * The two fields of a freshly created Stripe Checkout Session that matter
 * here: its id (kept on the CheckoutOrder) and the hosted-page URL the payer
 * must be redirected to.
 */
final readonly class StripeCheckoutSession
{
    public function __construct(
        public string $id,
        public string $url,
    ) {
    }
}
