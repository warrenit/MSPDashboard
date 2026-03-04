<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) === '443');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$rootPath = dirname(__DIR__);

require_once $rootPath . '/src/Core/Config.php';
require_once $rootPath . '/src/Core/Database.php';
require_once $rootPath . '/src/Core/InstallGate.php';
require_once $rootPath . '/src/Core/IpAllowlist.php';
require_once $rootPath . '/src/Core/Crypto.php';
require_once $rootPath . '/src/Core/CacheRepo.php';
require_once $rootPath . '/src/Core/SettingsRepo.php';
require_once $rootPath . '/src/Core/Auth.php';
require_once $rootPath . '/src/Services/MigrationService.php';
require_once $rootPath . '/src/Services/HaloClient.php';
require_once $rootPath . '/src/Services/DashboardService.php';
require_once $rootPath . '/src/Services/RssService.php';
