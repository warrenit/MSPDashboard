<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(array $config): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $db = $config['db'] ?? [];
        $host = $db['host'] ?? '';
        $name = $db['name'] ?? '';
        $user = $db['user'] ?? '';
        $pass = $db['pass'] ?? '';
        $charset = $db['charset'] ?? 'utf8mb4';

        if ($host === '' || $name === '' || $user === '') {
            throw new PDOException('Database configuration is incomplete.');
        }

        $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$pdo;
    }

    public static function testConnection(string $host, string $name, string $user, string $pass, string $charset = 'utf8mb4'): bool
    {
        $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return (bool) $pdo->query('SELECT 1')->fetchColumn();
    }
}
