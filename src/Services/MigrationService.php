<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class MigrationService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(string $migrationFile): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(255) PRIMARY KEY, applied_at DATETIME NOT NULL)');

        $version = basename($migrationFile);
        $stmt = $this->pdo->prepare('SELECT version FROM schema_migrations WHERE version = :version LIMIT 1');
        $stmt->execute(['version' => $version]);

        if ($stmt->fetch()) {
            return;
        }

        $sql = file_get_contents($migrationFile);
        if ($sql === false) {
            throw new \RuntimeException('Failed to read migration file.');
        }

        $this->pdo->exec($sql);

        $insert = $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, NOW())');
        $insert->execute(['version' => $version]);
    }
}
