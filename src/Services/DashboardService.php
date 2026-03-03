<?php

declare(strict_types=1);

namespace App\Services;

final class DashboardService
{
    public function payload(): array
    {
        return [
            'updatedAt' => gmdate('c'),
            'message' => 'Phase 1 complete. Dashboard integrations not implemented yet.',
        ];
    }
}
