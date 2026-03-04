<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class SettingsRepo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => $key]);
        $row = $stmt->fetch();

        if (!$row) {
            return $default;
        }

        return $row['setting_value'];
    }

    public function set(string $key, string $value): void
    {
        $sql = 'INSERT INTO settings (setting_key, setting_value, updated_at)
                VALUES (:setting_key, :setting_value, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'setting_key' => $key,
            'setting_value' => $value,
        ]);
    }
}
