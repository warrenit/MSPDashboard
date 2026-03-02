<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class EncryptionService
{
    private string $key;

    public function __construct(string $keyFile)
    {
        if (!is_file($keyFile)) {
            throw new RuntimeException('Encryption key file not found.');
        }

        $key = trim((string) file_get_contents($keyFile));
        if ($key === '') {
            throw new RuntimeException('Encryption key file is empty.');
        }

        $decoded = base64_decode($key, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Encryption key must be base64 and 32 bytes after decoding.');
        }

        $this->key = $decoded;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid encrypted payload.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt payload.');
        }

        return $plaintext;
    }
}
