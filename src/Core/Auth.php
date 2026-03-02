<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Auth
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function attempt(string $username, string $password): bool
    {
        $stmt = $this->pdo->prepare('SELECT id, password_hash FROM admin_users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            return false;
        }

        $_SESSION['admin_user_id'] = (int) $row['id'];
        $_SESSION['admin_username'] = $username;

        return true;
    }

    public function check(): bool
    {
        return isset($_SESSION['admin_user_id']);
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
