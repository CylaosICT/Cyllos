<?php

namespace App\Tests\Unit\Security;

use App\Security\TotpService;
use PHPUnit\Framework\TestCase;

class TotpServiceTest extends TestCase
{
    private TotpService $service;

    protected function setUp(): void
    {
        $this->service = new TotpService();
    }

    public function testGeneratedSecretIsValidBase32(): void
    {
        $secret = $this->service->generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
    }

    public function testVerifyAcceptsACodeGeneratedForTheCurrentSecret(): void
    {
        $secret = $this->service->generateSecret();
        $code = $this->generateCodeForTesting($secret, (int) floor(time() / 30));

        self::assertTrue($this->service->verify($secret, $code));
    }

    public function testVerifyRejectsAWrongCode(): void
    {
        $secret = $this->service->generateSecret();

        self::assertFalse($this->service->verify($secret, '000000'));
    }

    public function testVerifyRejectsMalformedInput(): void
    {
        $secret = $this->service->generateSecret();

        self::assertFalse($this->service->verify($secret, 'abcdef'));
        self::assertFalse($this->service->verify($secret, '123'));
    }

    public function testMatchesTheRfc6238ReferenceVector(): void
    {
        // RFC 6238 Appendix B: ASCII secret "12345678901234567890", T=59s
        // (counter=1) yields the 8-digit OTP 94287082 under SHA-1; the
        // 6-digit truncation used here is that same value mod 1_000_000.
        $code = $this->generateCodeForTesting('12345678901234567890', 1, asciiSecret: true);

        self::assertSame('287082', $code);
    }

    private function generateCodeForTesting(string $secret, int $counter, bool $asciiSecret = false): string
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCode');

        $secretBinary = $asciiSecret ? $secret : $this->decodeForTesting($secret);

        return $method->invoke($this->service, $secretBinary, $counter);
    }

    private function decodeForTesting(string $secret): string
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('base32Decode');

        return $method->invoke($this->service, $secret);
    }
}
