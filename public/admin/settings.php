<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\InstallGate;
use App\Core\IpAllowlist;
use App\Core\SettingsRepo;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/settings.php', PHP_URL_PATH) ?: '/admin/settings.php';
InstallGate::enforce($path);

$config = Config::load();
$pdo = Database::connect($config);
$settings = new SettingsRepo($pdo);
IpAllowlist::enforce($settings, $path);
Auth::requireLogin();

$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ipAllowlist = trim((string) ($_POST['ip_allowlist'] ?? ''));
    $refreshInterval = max(10, (int) ($_POST['refresh_interval_sec'] ?? 10));
    $cacheInterval = max(30, (int) ($_POST['cache_interval_sec'] ?? 30));

    $settings->set('ip_allowlist', $ipAllowlist);
    $settings->set('refresh_interval_sec', (string) $refreshInterval);
    $settings->set('cache_interval_sec', (string) $cacheInterval);
    $success = 'Settings saved.';
}

$currentIpAllowlist = (string) $settings->get('ip_allowlist', '');
$currentRefresh = (string) $settings->get('refresh_interval_sec', '10');
$currentCache = (string) $settings->get('cache_interval_sec', '30');
?><!doctype html>
<html><head><meta charset="utf-8"><title>Admin Settings</title><link rel="stylesheet" href="/assets/dashboard.css"></head>
<body><div class="container"><h1>Admin Settings (Phase 1)</h1>
<?php if ($success !== ''): ?><p class="success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post">
<label>IP allowlist (comma-separated public IPs)
<input type="text" name="ip_allowlist" value="<?= htmlspecialchars($currentIpAllowlist, ENT_QUOTES, 'UTF-8') ?>"></label>
<label>Refresh interval (seconds)
<input type="number" min="10" name="refresh_interval_sec" value="<?= htmlspecialchars($currentRefresh, ENT_QUOTES, 'UTF-8') ?>"></label>
<label>Cache interval (seconds)
<input type="number" min="30" name="cache_interval_sec" value="<?= htmlspecialchars($currentCache, ENT_QUOTES, 'UTF-8') ?>"></label>
<button type="submit">Save settings</button>
</form>
<p><a href="/admin/logout.php">Logout</a></p>
</div></body></html>
