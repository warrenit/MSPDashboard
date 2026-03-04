<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\InstallGate;
use App\Core\IpAllowlist;
use App\Core\SettingsRepo;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/login.php', PHP_URL_PATH) ?: '/admin/login.php';
InstallGate::enforce($path);

$error = '';
try {
    $pdo = Database::connect(Config::load());
    $settings = new SettingsRepo($pdo);
    IpAllowlist::enforce($settings, $path);

    if (Auth::check()) {
        header('Location: /admin/settings.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $auth = new Auth($pdo);
        if ($auth->attemptLogin($username, $password)) {
            header('Location: /admin/settings.php');
            exit;
        }
        $error = 'Invalid credentials.';
    }
} catch (Throwable $e) {
    $error = 'Configuration error.';
}
?><!doctype html>
<html><head><meta charset="utf-8"><title>Admin Login</title><link rel="stylesheet" href="/assets/dashboard.css"></head>
<body><div class="container"><h1>Admin Login</h1>
<?php if ($error !== ''): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post">
<label>Username <input type="text" name="username" required></label>
<label>Password <input type="password" name="password" required></label>
<button type="submit">Login</button>
</form>
</div></body></html>
