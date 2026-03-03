<?php

declare(strict_types=1);

namespace App\Core;

final class InstallGate
{
    public static function enforce(string $path): void
    {
        $installed = Config::isInstalled();
        $isInstallPath = str_starts_with($path, '/install');

        if (!$installed && !$isInstallPath) {
            header('Location: /install/');
            exit;
        }

        if ($installed && $isInstallPath) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Installation is already complete. Delete _config/installed.lock to run installer again.';
            exit;
        }
    }
}
