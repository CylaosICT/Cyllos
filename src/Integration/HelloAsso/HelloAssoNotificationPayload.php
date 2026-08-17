<?php

namespace App\Integration\HelloAsso;

/**
 * Structurally-parsed "Payment" webhook notification from HelloAsso, before any
 * business validation (form slug match, amount threshold, state, lateness...).
 */
final readonly class HelloAssoNotificationPayload
{
    public function __construct(
        public int $helloAssoPaymentId,
        public int $amountCents,
        public string $rawDate,
        public string $state,
        public string $payerFirstName,
        public string $payerLastName,
        public string $payerEmail,
        public string $formSlug,
    ) {
    }
}
