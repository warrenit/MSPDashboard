<?php

declare(strict_types=1);

namespace App\Core;

final class IpAllowlist
{
    public static function enforce(?SettingsRepo $settingsRepo, string $path): void
    {
        if (str_starts_with($path, '/install')) {
            return;
        }

        if ($settingsRepo === null) {
            return;
        }

        $csv = (string) $settingsRepo->get('ip_allowlist', '');
        $allowed = array_values(array_filter(array_map('trim', explode(',', $csv))));

        if ($allowed === []) {
            return;
        }

        $clientIp = self::clientIp();
        if (!in_array($clientIp, $allowed, true)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Access denied for this IP.';
            exit;
        }
    }

    private static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
