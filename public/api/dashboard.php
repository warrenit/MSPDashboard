<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\InstallGate;
use App\Core\IpAllowlist;
use App\Core\SettingsRepo;
use App\Services\DashboardService;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/api/dashboard.php', PHP_URL_PATH) ?: '/api/dashboard.php';
InstallGate::enforce($path);

header('Content-Type: application/json; charset=utf-8');

try {
    $config = Config::load();
    $pdo = Database::connect($config);
    $settings = new SettingsRepo($pdo);
    IpAllowlist::enforce($settings, $path);

    $service = new DashboardService($settings);
    echo json_encode($service->payload(), JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to load dashboard data.',
        'apiStatus' => [
            'halo' => ['state' => 'red', 'message' => 'Error', 'updatedAt' => null],
            'datto' => ['state' => 'red', 'message' => 'Error', 'updatedAt' => null],
            'kuma' => ['state' => 'red', 'message' => 'Error', 'updatedAt' => null],
            'rss' => ['state' => 'red', 'message' => 'Error', 'updatedAt' => null],
        ],
    ]);
}
