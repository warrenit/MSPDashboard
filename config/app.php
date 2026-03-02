<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_NAME') ?: 'msp_dashboard',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
    ],
    'security' => [
        // Must point to a file outside webroot containing a base64-encoded 32-byte key.
        'app_key_file' => getenv('APP_KEY_FILE') ?: dirname(__DIR__) . '/../mspdashboard_app.key',
    ],
];
