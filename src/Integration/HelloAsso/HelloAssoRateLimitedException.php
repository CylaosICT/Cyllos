<?php

namespace App\Integration\HelloAsso;

/**
 * HelloAsso answered HTTP 429 (or we are still inside a cool-down following one).
 * Carries the number of seconds to wait before trying again so the caller can
 * reschedule instead of hammering the API.
 */
class HelloAssoRateLimitedException extends HelloAssoException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
