<?php

namespace App\Tests\Unit\Integration\Stripe;

use App\Integration\Stripe\StripeWebhookVerifier;
use PHPUnit\Framework\TestCase;

class StripeWebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec_testsecret0123456789';
    private const NOW = 1_800_000_000;

    private function header(string $payload, int $timestamp = self::NOW, string $secret = self::SECRET): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    public function testAcceptsAValidSignature(): void
    {
        $payload = '{"id":"evt_1"}';

        self::assertTrue((new StripeWebhookVerifier())->verify($payload, $this->header($payload), self::SECRET, now: self::NOW));
    }

    public function testAcceptsWhenOneOfSeveralSignaturesMatches(): void
    {
        $payload = '{"id":"evt_1"}';
        $header = 't=' . self::NOW . ',v1=' . str_repeat('0', 64) . ',v0=ignored,v1=' . hash_hmac('sha256', self::NOW . '.' . $payload, self::SECRET);

        self::assertTrue((new StripeWebhookVerifier())->verify($payload, $header, self::SECRET, now: self::NOW));
    }

    public function testRejectsATamperedBody(): void
    {
        $header = $this->header('{"id":"evt_1"}');

        self::assertFalse((new StripeWebhookVerifier())->verify('{"id":"evt_2"}', $header, self::SECRET, now: self::NOW));
    }

    public function testRejectsTheWrongSecret(): void
    {
        $payload = '{"id":"evt_1"}';

        self::assertFalse((new StripeWebhookVerifier())->verify($payload, $this->header($payload, secret: 'whsec_other'), self::SECRET, now: self::NOW));
    }

    public function testRejectsAReplayedOldDelivery(): void
    {
        $payload = '{"id":"evt_1"}';
        $header = $this->header($payload, self::NOW - 301);

        self::assertFalse((new StripeWebhookVerifier())->verify($payload, $header, self::SECRET, now: self::NOW));
        self::assertTrue((new StripeWebhookVerifier())->verify($payload, $this->header($payload, self::NOW - 299), self::SECRET, now: self::NOW));
    }

    public function testRejectsMissingOrMalformedHeaders(): void
    {
        $verifier = new StripeWebhookVerifier();

        self::assertFalse($verifier->verify('{}', null, self::SECRET, now: self::NOW));
        self::assertFalse($verifier->verify('{}', '', self::SECRET, now: self::NOW));
        self::assertFalse($verifier->verify('{}', 'garbage', self::SECRET, now: self::NOW));
        self::assertFalse($verifier->verify('{}', 't=abc,v1=deadbeef', self::SECRET, now: self::NOW));
        self::assertFalse($verifier->verify('{}', 't=' . self::NOW, self::SECRET, now: self::NOW));
    }

    public function testRejectsAnEmptySecret(): void
    {
        $payload = '{}';

        self::assertFalse((new StripeWebhookVerifier())->verify($payload, $this->header($payload, secret: ''), '', now: self::NOW));
    }
}
