<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\InstallGate;
use App\Core\IpAllowlist;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
InstallGate::enforce($path);

$config = Config::load();
$settingsRepo = null;
try {
    $pdo = Database::connect($config);
    $settingsRepo = new App\Core\SettingsRepo($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Configuration error.';
    exit;
}

IpAllowlist::enforce($settingsRepo, $path);
?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Helpdesk Dashboard</title>
    <link rel="stylesheet" href="/assets/dashboard.css">
</head>
<body>
<div class="container">
    <h1>Helpdesk Dashboard</h1>
    <p>Phase 1 plumbing is complete.</p>
    <p><a href="/admin/settings.php">Admin settings</a></p>
</div>
</body>
</html>
