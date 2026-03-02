<?php

declare(strict_types=1);

use App\Core\ApiCacheRepository;
use App\Core\Auth;
use App\Core\Database;
use App\Core\EncryptionService;
use App\Core\SettingsRepository;
use App\Middleware\IpAllowlistMiddleware;
use App\Services\DashboardService;

session_start();

date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

$config = require __DIR__ . '/../config/app.php';
$pdo = Database::connection($config['db']);

$settingsRepo = new SettingsRepository($pdo);
$ipMiddleware = new IpAllowlistMiddleware($settingsRepo);
$encryption = new EncryptionService($config['security']['app_key_file']);
$auth = new Auth($pdo);
$cacheRepo = new ApiCacheRepository($pdo);
$dashboardService = new DashboardService($settingsRepo, $cacheRepo, $encryption);
