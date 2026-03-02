<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getAll(): array
    {
        $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM settings');
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return $settings;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? $default : (string) $value;
    }

    public function upsert(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute(['key' => $key, 'value' => $value]);
    }
}
