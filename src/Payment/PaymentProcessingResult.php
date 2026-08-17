<?php

namespace App\Payment;

use App\Entity\PaymentStatus;

final readonly class PaymentProcessingResult
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        public PaymentStatus $status,
        public array $errors = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful() || $this->status === PaymentStatus::PreviewOk;
    }
}
