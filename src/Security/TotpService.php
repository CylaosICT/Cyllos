<?php

namespace App\Security;

/**
 * Hand-rolled TOTP (RFC 6238, HMAC-SHA1, 30s step, 6 digits) — no external
 * library dependency. Compatible with standard authenticator apps (Google
 * Authenticator, Authy, 1Password, ...).
 */
class TotpService
{
    private const SECRET_BYTES = 20;
    private const PERIOD_SECONDS = 30;
    private const DIGITS = 6;
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(self::SECRET_BYTES));
    }

    public function getProvisioningUri(string $secret, string $accountLabel, string $issuer = 'Cyllos'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountLabel),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD_SECONDS,
        );
    }

    /**
     * Accepts a code from the current time step or one step before/after, to
     * tolerate clock drift and the time it takes a human to type the code.
     */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $counter = (int) floor(time() / self::PERIOD_SECONDS);
        $secretBinary = $this->base32Decode($secret);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->generateCode($secretBinary, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    private function generateCode(string $secretBinary, int $counter): string
    {
        $binaryCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $secretBinary, true);

        $offset = \ord($hash[\strlen($hash) - 1]) & 0x0F;
        $truncated = (
            ((\ord($hash[$offset]) & 0x7F) << 24)
            | (\ord($hash[$offset + 1]) << 16)
            | (\ord($hash[$offset + 2]) << 8)
            | \ord($hash[$offset + 3])
        );

        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $bits = '';
        foreach (str_split(strtoupper($encoded)) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (\strlen($byte) === 8) {
                $binary .= \chr(bindec($byte));
            }
        }

        return $binary;
    }
}
