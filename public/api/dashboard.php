<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
$ipMiddleware->enforce();

header('Content-Type: application/json; charset=utf-8');

try {
    echo json_encode($dashboardService->getDashboardPayload(), JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'apiStatus' => [
            'halo' => [
                'state' => 'red',
                'message' => 'Unexpected server error.',
            ],
        ],
        'tiles' => [
            'unassignedCount' => null,
        ],
        'updatedAt' => [
            'halo' => null,
            'dashboard' => gmdate(DATE_ATOM),
        ],
    ]);
}
