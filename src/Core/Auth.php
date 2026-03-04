<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Auth
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function attemptLogin(string $username, string $password): bool
    {
        $stmt = $this->pdo->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        $_SESSION['admin_user_id'] = (int) $user['id'];
        $_SESSION['admin_username'] = $user['username'];
        return true;
    }

    public static function check(): bool
    {
        return isset($_SESSION['admin_user_id']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /admin/login.php');
            exit;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }
}
