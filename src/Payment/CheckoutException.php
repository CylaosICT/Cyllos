<?php

namespace App\Payment;

/**
 * A top-up cannot be started. $reason is a stable machine code the payment
 * site maps to its own wording; the message is for logs.
 */
class CheckoutException extends \RuntimeException
{
    public const CLIENT_UNAVAILABLE = 'client_unavailable';
    public const INVALID_EMAIL = 'invalid_email';
    public const INVALID_AMOUNT = 'invalid_amount';
    public const ACCOUNT_NOT_FOUND = 'account_not_found';
    public const ACCOUNT_NOT_ELIGIBLE = 'account_not_eligible';
    public const PROVIDER_ERROR = 'provider_error';

    public function __construct(
        public readonly string $reason,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
