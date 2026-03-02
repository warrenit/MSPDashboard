<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\SettingsRepository;

final class IpAllowlistMiddleware
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function enforce(): void
    {
        $configured = trim((string) $this->settings->get('ip_allowlist', ''));
        if ($configured === '') {
            return;
        }

        $allowed = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $configured) ?: []));
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!in_array($clientIp, $allowed, true)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Access denied.';
            exit;
        }
    }
}
