<?php

namespace App\Integration\HelloAsso;

/**
 * A payment as returned by HelloAsso's payment history endpoint (used by the
 * catch-up fetch command, as opposed to the real-time webhook).
 */
final readonly class HelloAssoFetchedPayment
{
    public function __construct(
        public int $helloAssoPaymentId,
        public int $amountCents,
        public string $rawDate,
        public string $payerFirstName,
        public string $payerLastName,
        public string $payerEmail,
    ) {
    }
}
