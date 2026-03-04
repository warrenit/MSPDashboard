<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Crypto
{
    public static function method(): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            return 'sodium';
        }

        return 'openssl';
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public static function keyBytes(string $base64): string
    {
        $bytes = base64_decode(trim($base64), true);
        if ($bytes === false || strlen($bytes) !== 32) {
            throw new RuntimeException('Invalid app key.');
        }

        return $bytes;
    }

    public static function encryptString(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = self::appKeyBytes();
        if (self::method() === 'sodium') {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
            return 'sodium:' . base64_encode($nonce . $cipher);
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($cipher)) {
            throw new RuntimeException('Encryption failed.');
        }

        return 'openssl:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decryptString(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        $key = self::appKeyBytes();

        if (str_starts_with($encoded, 'sodium:')) {
            $raw = base64_decode(substr($encoded, 7), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                throw new RuntimeException('Invalid encrypted payload.');
            }
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            if ($plain === false) {
                throw new RuntimeException('Decryption failed.');
            }
            return $plain;
        }

        if (str_starts_with($encoded, 'openssl:')) {
            $raw = base64_decode(substr($encoded, 8), true);
            if ($raw === false || strlen($raw) <= 28) {
                throw new RuntimeException('Invalid encrypted payload.');
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if (!is_string($plain)) {
                throw new RuntimeException('Decryption failed.');
            }
            return $plain;
        }

        throw new RuntimeException('Unknown encrypted payload format.');
    }

    private static function appKeyBytes(): string
    {
        $file = Config::appKeyFile();
        if (!is_file($file)) {
            throw new RuntimeException('App key is missing.');
        }

        $raw = file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('App key is unreadable.');
        }

        return self::keyBytes(trim($raw));
    }
}
