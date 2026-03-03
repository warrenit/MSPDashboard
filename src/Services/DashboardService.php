<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\SettingsRepo;

final class DashboardService
{
    public function __construct(private readonly SettingsRepo $settings)
    {
    }

    public function payload(): array
    {
        $agentNames = $this->parseList((string) $this->settings->get('agent_names', 'Name'));
        if ($agentNames === []) {
            $agentNames = ['Name'];
        }

        $openByAgent = [];
        $closedByAgent = [];
        foreach ($agentNames as $name) {
            $openByAgent[] = ['agent' => $name, 'count' => 0];
            $closedByAgent[] = ['agent' => $name, 'count' => 0];
        }

        $rssEnabled = ((string) $this->settings->get('rss_enabled', '0')) === '1';

        return [
            'apiStatus' => [
                'halo' => ['state' => 'grey', 'message' => 'Not configured', 'updatedAt' => null],
                'datto' => ['state' => 'grey', 'message' => 'Not configured', 'updatedAt' => null],
                'kuma' => ['state' => 'grey', 'message' => 'Not configured', 'updatedAt' => null],
                'rss' => ['state' => 'grey', 'message' => $rssEnabled ? 'Enabled (not configured)' : 'Disabled', 'updatedAt' => null],
            ],
            'tiles' => [
                'unassignedCount' => 0,
                'importantAlertsCount' => 0,
                'totalOpenCount' => 0,
                'waitingOnCustomerCount' => 0,
                'customerRespondedCount' => 0,
                'slaDueSoonCount' => 0,
                'slaOverdueCount' => 0,
                'projectOpenCount' => 0,
                'dattoOpenAlertsCount' => 0,
                'kumaDownCount' => 0,
                'kumaFlapCount' => 0,
            ],
            'health' => [
                'state' => 'green',
                'reasons' => [],
            ],
            'charts' => [
                'openByAgent' => $openByAgent,
                'closedThisWeekByAgent' => $closedByAgent,
            ],
            'exceptions' => [
                'kumaDown' => [
                    ['name' => 'Service', 'durationSeconds' => 0],
                ],
            ],
            'rssTicker' => [
                'enabled' => $rssEnabled,
                'items' => [],
            ],
            'updatedAt' => [
                'overall' => gmdate('c'),
            ],
        ];
    }

    private function parseList(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: [])));
    }
}
