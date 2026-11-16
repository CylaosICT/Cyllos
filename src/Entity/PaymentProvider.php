<?php

namespace App\Entity;

/**
 * The payment service a client collects its top-ups through. Chosen once, at
 * client creation (wizard step 2), and stored on Client::$paymentProvider.
 */
enum PaymentProvider: string
{
    case HelloAsso = 'helloasso';
    case Stripe = 'stripe';

    public function label(): string
    {
        return match ($this) {
            self::HelloAsso => 'HelloAsso',
            self::Stripe => 'Stripe',
        };
    }
}
