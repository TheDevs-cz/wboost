<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Security;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\TokenDecryptionFailed;
use WBoost\Web\Services\Security\TokenCrypto;

final class TokenCryptoTest extends TestCase
{
    private function crypto(): TokenCrypto
    {
        return new TokenCrypto(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function testRoundTrip(): void
    {
        $crypto = $this->crypto();

        $encrypted = $crypto->encrypt('EAAG-long-lived-facebook-token');

        self::assertNotSame('EAAG-long-lived-facebook-token', $encrypted);
        self::assertSame('EAAG-long-lived-facebook-token', $crypto->decrypt($encrypted));
    }

    public function testEncryptionIsRandomizedPerCall(): void
    {
        $crypto = $this->crypto();

        self::assertNotSame($crypto->encrypt('same-token'), $crypto->encrypt('same-token'));
    }

    public function testTamperedCiphertextThrows(): void
    {
        $crypto = $this->crypto();
        $encrypted = $crypto->encrypt('token');

        $bytes = base64_decode($encrypted, true);
        self::assertIsString($bytes);
        $bytes[strlen($bytes) - 1] = $bytes[strlen($bytes) - 1] === 'a' ? 'b' : 'a';

        $this->expectException(TokenDecryptionFailed::class);
        $crypto->decrypt(base64_encode($bytes));
    }

    public function testGarbageInputThrows(): void
    {
        $this->expectException(TokenDecryptionFailed::class);
        $this->crypto()->decrypt('not-even-base64!!');
    }

    public function testDifferentKeyCannotDecrypt(): void
    {
        $encrypted = $this->crypto()->encrypt('token');
        $otherCrypto = new TokenCrypto(str_repeat('x', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $this->expectException(TokenDecryptionFailed::class);
        $otherCrypto->decrypt($encrypted);
    }
}
