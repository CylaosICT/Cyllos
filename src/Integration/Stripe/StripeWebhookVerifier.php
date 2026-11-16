<?php

namespace App\Integration\Stripe;

/**
 * Verifies the `Stripe-Signature` header of a webhook delivery, per Stripe's
 * documented scheme: the header carries a timestamp (`t=`) and one or more
 * `v1=` HMAC-SHA256 signatures of "{timestamp}.{raw body}" computed with the
 * endpoint's signing secret (`whsec_…`). The timestamp is bounded to defeat
 * replays of a captured delivery.
 */
class StripeWebhookVerifier
{
    /** Stripe's own libraries default to 5 minutes. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    public function verify(
        string $payload,
        ?string $signatureHeader,
        #[\SensitiveParameter] string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): bool {
        if ($signatureHeader === null || $signatureHeader === '' || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (\count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't') {
                $timestamp = ctype_digit($pair[1]) ? (int) $pair[1] : null;
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
