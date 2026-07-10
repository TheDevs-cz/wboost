<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts third-party OAuth access tokens at rest (sodium secretbox,
 * XSalsa20-Poly1305). Stored format: base64(nonce ‖ ciphertext). The key is a
 * deployment secret (SOCIAL_TOKEN_ENCRYPTION_KEY, 32 bytes base64) — rotating
 * it invalidates every stored token, which is recoverable: users reconnect.
 */
readonly final class TokenCrypto
{
    public function __construct(
        #[Autowire('%env(base64:SOCIAL_TOKEN_ENCRYPTION_KEY)%')]
        #[SensitiveParameter]
        private string $key,
    ) {
    }

    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    /**
     * @throws \WBoost\Web\Exceptions\TokenDecryptionFailed
     */
    public function decrypt(string $encrypted): string
    {
        $decoded = base64_decode($encrypted, true);

        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \WBoost\Web\Exceptions\TokenDecryptionFailed();
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new \WBoost\Web\Exceptions\TokenDecryptionFailed();
        }

        return $plaintext;
    }
}
