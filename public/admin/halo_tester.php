<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\CacheRepo;
use App\Core\Config;
use App\Core\Database;
use App\Core\InstallGate;
use App\Core\IpAllowlist;
use App\Core\SettingsRepo;
use App\Services\HaloClient;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/halo_tester.php', PHP_URL_PATH) ?: '/admin/halo_tester.php';
InstallGate::enforce($path);

$config = Config::load();
$pdo = Database::connect($config);
$settings = new SettingsRepo($pdo);
IpAllowlist::enforce($settings, $path);
Auth::requireLogin();

$relativePath = trim((string) ($_GET['path'] ?? '/tickets'));
$queryString = trim((string) ($_GET['query'] ?? 'page_no=1&page_size=1'));
$result = null;
$error = '';

if (isset($_GET['run']) && $_GET['run'] === '1') {
    try {
        $haloClient = new HaloClient($settings, new CacheRepo($pdo));
        $result = $haloClient->diagnosticRequest($relativePath, $queryString);
    } catch (Throwable $e) {
        $error = 'Request failed: ' . preg_replace('/[^a-zA-Z0-9 .:_\-\/]/', '', $e->getMessage());
    }
}
?><!doctype html>
<html><head><meta charset="utf-8"><title>Halo API Tester</title><link rel="stylesheet" href="/assets/dashboard.css"></head>
<body>
<div class="container">
    <h1>Halo API Tester</h1>
    <p class="error"><strong>Diagnostics only:</strong> use this page carefully. Do not share sensitive output.</p>
    <form method="get">
        <label>Relative path
            <input type="text" name="path" value="<?= htmlspecialchars($relativePath, ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <label>Querystring
            <input type="text" name="query" value="<?= htmlspecialchars($queryString, ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <input type="hidden" name="run" value="1">
        <button type="submit">Run request</button>
    </form>

    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if (is_array($result)): ?>
        <h2>Result</h2>
        <p>Status: <?= (int) ($result['status'] ?? 0) ?></p>
        <?php if (!empty($result['error'])): ?><p class="error"><?= htmlspecialchars((string)$result['error'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <pre style="white-space:pre-wrap;max-height:480px;overflow:auto;background:#0b1424;padding:10px;border-radius:8px;border:1px solid #30405e;"><?= htmlspecialchars((string) ($result['preview'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre>
    <?php endif; ?>

    <p><a href="/admin/settings.php?section=halo">Back to Halo settings</a></p>
</div>
</body></html>
