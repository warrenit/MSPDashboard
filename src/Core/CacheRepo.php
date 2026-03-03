<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class CacheRepo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function upsert(string $source, array $payload, string $status = 'ok', ?string $lastError = null): void
    {
        $sql = 'INSERT INTO source_cache (source_name, payload_json, status, last_error, updated_at)
                VALUES (:source_name, :payload_json, :status, :last_error, NOW())
                ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), status = VALUES(status), last_error = VALUES(last_error), updated_at = NOW()';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'source_name' => $source,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => $status,
            'last_error' => $lastError,
        ]);
    }
}
