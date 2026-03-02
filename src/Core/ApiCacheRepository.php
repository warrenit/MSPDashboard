<?php

declare(strict_types=1);

namespace App\Core;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ApiCacheRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $cacheKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT cache_value, fetched_at, expires_at FROM api_cache WHERE cache_key = :cache_key LIMIT 1');
        $stmt->execute(['cache_key' => $cacheKey]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return [
            'value' => json_decode($row['cache_value'], true),
            'fetched_at' => $row['fetched_at'],
            'expires_at' => $row['expires_at'],
            'is_fresh' => strtotime($row['expires_at']) >= time(),
        ];
    }

    public function put(string $cacheKey, array $payload, int $ttlSeconds): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->add(new DateInterval('PT' . max(1, $ttlSeconds) . 'S'));

        $stmt = $this->pdo->prepare(
            'INSERT INTO api_cache (cache_key, cache_value, fetched_at, expires_at)
            VALUES (:cache_key, :cache_value, :fetched_at, :expires_at)
            ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), fetched_at = VALUES(fetched_at), expires_at = VALUES(expires_at)'
        );

        $stmt->execute([
            'cache_key' => $cacheKey,
            'cache_value' => json_encode($payload, JSON_THROW_ON_ERROR),
            'fetched_at' => $now->format('Y-m-d H:i:s'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
    }
}
