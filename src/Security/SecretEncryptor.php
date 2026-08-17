<?php

namespace App\Security;

/**
 * Encrypts secrets (HelloAsso client secret, Cyclos password) at rest using
 * AES-256-GCM, keyed by APP_ENCRYPTION_KEY.
 */
class SecretEncryptor
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_BYTES = 32;
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    private string $key;

    public function __construct(#[\SensitiveParameter] string $encryptionKey)
    {
        $decoded = base64_decode($encryptionKey, true);
        if ($decoded === false || \strlen($decoded) !== self::KEY_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'APP_ENCRYPTION_KEY must be a base64-encoded %d-byte key. Generate one with: php bin/console app:generate-encryption-key',
                self::KEY_BYTES,
            ));
        }
        $this->key = $decoded;
    }

    public function encrypt(string $plainText): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new \RuntimeException('Failed to encrypt secret.');
        }

        return base64_encode($iv . $tag . $cipherText);
    }

    public function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || \strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            throw new \RuntimeException('Invalid encrypted payload.');
        }

        $iv = substr($raw, 0, self::IV_BYTES);
        $tag = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $cipherText = substr($raw, self::IV_BYTES + self::TAG_BYTES);

        $plainText = openssl_decrypt($cipherText, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plainText === false) {
            throw new \RuntimeException('Failed to decrypt secret: payload authentication failed.');
        }

        return $plainText;
    }
}
