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
        $bytes = base64_decode($base64, true);
        if ($bytes === false || strlen($bytes) !== 32) {
            throw new RuntimeException('Invalid app key.');
        }

        return $bytes;
    }
}
