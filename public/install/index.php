<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\InstallGate;
use App\Core\SettingsRepo;
use App\Services\MigrationService;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/install/', PHP_URL_PATH) ?: '/install/';
InstallGate::enforce($path);

$step = max(1, min(6, (int) ($_GET['step'] ?? 1)));
$error = '';
$success = '';

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($step === 1) {
            $configDir = Config::configDir();
            if (!is_dir($configDir) && !mkdir($configDir, 0770, true) && !is_dir($configDir)) {
                throw new RuntimeException('Unable to create _config directory.');
            }
            if (!is_writable($configDir)) {
                throw new RuntimeException('_config directory is not writable.');
            }
            header('Location: /install/?step=2');
            exit;
        }

        if ($step === 2) {
            $host = trim((string) ($_POST['db_host'] ?? ''));
            $name = trim((string) ($_POST['db_name'] ?? ''));
            $user = trim((string) ($_POST['db_user'] ?? ''));
            $pass = (string) ($_POST['db_pass'] ?? '');

            if ($host === '' || $name === '' || $user === '') {
                throw new RuntimeException('DB host, name, and user are required.');
            }

            Database::testConnection($host, $name, $user, $pass);

            $config = [
                'db' => [
                    'host' => $host,
                    'name' => $name,
                    'user' => $user,
                    'pass' => $pass,
                    'charset' => 'utf8mb4',
                ],
            ];

            $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
            if (file_put_contents(Config::configFile(), $content, LOCK_EX) === false) {
                throw new RuntimeException('Failed to write config file.');
            }

            header('Location: /install/?step=3');
            exit;
        }

        if ($step === 3) {
            $pdo = Database::connect(Config::load());
            $migrationService = new MigrationService($pdo);
            $migrationService->run(dirname(__DIR__, 2) . '/database/migrations/001_init.sql');
            header('Location: /install/?step=4');
            exit;
        }

        if ($step === 4) {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            if ($username === '' || strlen($password) < 8) {
                throw new RuntimeException('Username and password (min 8 chars) are required.');
            }

            $pdo = Database::connect(Config::load());
            $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash, created_at, updated_at) VALUES (:username, :password_hash, NOW(), NOW()) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), updated_at = NOW()');
            $stmt->execute([
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            header('Location: /install/?step=5');
            exit;
        }

        if ($step === 5) {
            $pdo = Database::connect(Config::load());
            $settings = new SettingsRepo($pdo);
            $settings->set('ip_allowlist', trim((string) ($_POST['ip_allowlist'] ?? '')));
            $settings->set('refresh_interval_sec', (string) max(10, (int) ($_POST['refresh_interval_sec'] ?? 10)));
            $settings->set('cache_interval_sec', (string) max(30, (int) ($_POST['cache_interval_sec'] ?? 30)));
            header('Location: /install/?step=6');
            exit;
        }

        if ($step === 6) {
            if (!is_file(Config::appKeyFile())) {
                $key = Crypto::generateKey();
                if (file_put_contents(Config::appKeyFile(), $key . PHP_EOL, LOCK_EX) === false) {
                    throw new RuntimeException('Unable to write app key file.');
                }
                @chmod(Config::appKeyFile(), 0600);
            }

            $lockBody = "Installed at " . gmdate('c');
            if (file_put_contents(Config::lockFile(), $lockBody . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write installed lock file.');
            }

            header('Location: /admin/settings.php');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$checks = [
    'PHP >= 8.0' => PHP_VERSION_ID >= 80000,
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'curl' => extension_loaded('curl'),
    'json' => extension_loaded('json'),
    'sodium OR openssl' => function_exists('sodium_crypto_secretbox') || extension_loaded('openssl'),
];

$cryptoMethod = Crypto::method();
?><!doctype html>
<html><head><meta charset="utf-8"><title>Install Wizard</title><link rel="stylesheet" href="/assets/dashboard.css"></head>
<body><div class="container"><h1>Install Wizard (Step <?= $step ?> of 6)</h1>
<?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<?php if ($success !== ''): ?><p class="success"><?= h($success) ?></p><?php endif; ?>

<?php if ($step === 1): ?>
    <h2>1) Requirements</h2>
    <ul>
        <?php foreach ($checks as $name => $ok): ?>
            <li><?= h($name) ?>: <?= $ok ? 'OK' : 'Missing' ?></li>
        <?php endforeach; ?>
        <li>Crypto selected: <?= h($cryptoMethod) ?></li>
    </ul>
    <p>Installer will create <code>_config/</code> if needed.</p>
    <form method="post"><button type="submit">Continue</button></form>
<?php elseif ($step === 2): ?>
    <h2>2) Database Setup</h2>
    <form method="post">
        <label>DB Host <input type="text" name="db_host" required></label>
        <label>DB Name <input type="text" name="db_name" required></label>
        <label>DB User <input type="text" name="db_user" required></label>
        <label>DB Password <input type="password" name="db_pass"></label>
        <button type="submit">Test + Save DB Config</button>
    </form>
<?php elseif ($step === 3): ?>
    <h2>3) Run Migrations</h2>
    <p>This executes <code>database/migrations/001_init.sql</code> and records it in <code>schema_migrations</code>.</p>
    <form method="post"><button type="submit">Run Migration</button></form>
<?php elseif ($step === 4): ?>
    <h2>4) Create Admin Account</h2>
    <form method="post">
        <label>Admin Username <input type="text" name="username" required></label>
        <label>Admin Password (min 8 chars) <input type="password" name="password" required></label>
        <button type="submit">Create Admin</button>
    </form>
<?php elseif ($step === 5): ?>
    <h2>5) Optional IP Allowlist + Intervals</h2>
    <form method="post">
        <label>IP allowlist (comma-separated public IPs)
            <input type="text" name="ip_allowlist" placeholder="203.0.113.10,198.51.100.24">
        </label>
        <label>Refresh interval (seconds)
            <input type="number" min="10" name="refresh_interval_sec" value="10">
        </label>
        <label>Cache interval (seconds)
            <input type="number" min="30" name="cache_interval_sec" value="30">
        </label>
        <button type="submit">Save + Continue</button>
    </form>
<?php elseif ($step === 6): ?>
    <h2>6) Finish</h2>
    <p>Generate <code>_config/app.key</code> and write <code>_config/installed.lock</code>.</p>
    <form method="post"><button type="submit">Finish Installation</button></form>
<?php endif; ?>

</div></body></html>
