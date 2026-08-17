<?php

namespace App\Integration\Cyclos;

final readonly class CyclosPaymentResult
{
    private function __construct(
        public bool $success,
        public bool $preview,
        public ?string $errorMessage,
    ) {
    }

    public static function success(bool $preview): self
    {
        return new self(true, $preview, null);
    }

    public static function failure(string $errorMessage): self
    {
        return new self(false, false, $errorMessage);
    }
}
