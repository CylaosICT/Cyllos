<?php

namespace App\Tests\Unit\Security;

use App\Security\SecretEncryptor;
use PHPUnit\Framework\TestCase;

class SecretEncryptorTest extends TestCase
{
    private SecretEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->encryptor = new SecretEncryptor(base64_encode(str_repeat('a', 32)));
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $plainText = 'super-secret-cyclos-password';

        $encrypted = $this->encryptor->encrypt($plainText);

        self::assertNotSame($plainText, $encrypted);
        self::assertSame($plainText, $this->encryptor->decrypt($encrypted));
    }

    public function testEncryptingTwiceProducesDifferentCiphertext(): void
    {
        $plainText = 'same-input';

        self::assertNotSame($this->encryptor->encrypt($plainText), $this->encryptor->encrypt($plainText));
    }

    public function testDecryptingEmptyStringReturnsEmptyString(): void
    {
        self::assertSame('', $this->encryptor->decrypt(''));
    }

    public function testTamperedCiphertextFailsToDecrypt(): void
    {
        $encrypted = $this->encryptor->encrypt('some secret value');
        $tampered = substr($encrypted, 0, -4) . 'abcd';

        $this->expectException(\RuntimeException::class);

        $this->encryptor->decrypt($tampered);
    }

    public function testRejectsInvalidKeyLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SecretEncryptor(base64_encode('too-short'));
    }
}
