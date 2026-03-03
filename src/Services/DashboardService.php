<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\CacheRepo;
use App\Core\SettingsRepo;

final class DashboardService
{
    public function __construct(
        private readonly SettingsRepo $settings,
        private readonly RssService $rssService,
        private readonly HaloClient $haloClient,
        private readonly CacheRepo $cacheRepo
    ) {
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

        $rss = $this->rssService->getTickerData();
        $halo = $this->resolveHaloData();

        $tiles = [
            'unassignedCount' => 0,
            'importantAlertsCount' => 0,
            'totalOpenCount' => 0,
            'waitingOnCustomerCount' => 0,
            'customerRespondedCount' => 0,
            'slaDueSoonCount' => 0,
            'slaOverdueCount' => 0,
            'projectOpenCount' => 0,
            'dattoOpenAlertsCount' => 0,
            'oldestOpenTicketAgeSeconds' => null,
            'avgFirstResponseMinutesToday' => null,
        ];

        $charts = [
            'openByAgent' => $openByAgent,
            'closedThisWeekByAgent' => $closedByAgent,
        ];

        if (isset($halo['data']['tiles']) && is_array($halo['data']['tiles'])) {
            $tiles = array_merge($tiles, $halo['data']['tiles']);
        }

        if (isset($halo['data']['charts']) && is_array($halo['data']['charts'])) {
            $charts = array_merge($charts, $halo['data']['charts']);
        }

        return [
            'apiStatus' => [
                'halo' => $halo['status'],
                'datto' => ['state' => 'grey', 'message' => 'Not configured', 'updatedAt' => null],
                'kuma' => ['state' => 'grey', 'message' => 'Not configured', 'updatedAt' => null],
                'rss' => $rss['status'],
            ],
            'tiles' => $tiles,
            'health' => [
                'state' => 'green',
                'reasons' => [],
            ],
            'charts' => $charts,
            'exceptions' => [
                'kumaDown' => [
                    ['name' => 'Service', 'durationSeconds' => 0],
                ],
            ],
            'rssTicker' => $rss['ticker'],
            'updatedAt' => [
                'overall' => gmdate('c'),
            ],
        ];
    }

    private function resolveHaloData(): array
    {
        if (!$this->haloClient->isEnabled()) {
            return [
                'status' => ['state' => 'grey', 'message' => 'Disabled', 'updatedAt' => null],
                'data' => null,
            ];
        }

        if (!$this->haloClient->hasCredentials()) {
            return [
                'status' => ['state' => 'grey', 'message' => 'Missing credentials', 'updatedAt' => null],
                'data' => null,
            ];
        }

        $cacheInterval = max(30, (int) $this->settings->get('cache_interval_sec', '60'));
        $cached = $this->cacheRepo->get('halo_dashboard');
        $cachedAge = $this->ageSeconds($cached['updated_at'] ?? null);

        if ($cached !== null && $cachedAge !== null && $cachedAge <= $cacheInterval) {
            return [
                'status' => ['state' => 'green', 'message' => 'OK (cached)', 'updatedAt' => $this->toIso($cached['updated_at'])],
                'data' => is_array($cached['payload']) ? $cached['payload'] : null,
            ];
        }

        $fresh = $this->haloClient->fetchDashboardData();
        if ($fresh['ok'] && is_array($fresh['data'])) {
            $this->cacheRepo->upsert('halo_dashboard', $fresh['data'], 'ok', null);
            return [
                'status' => ['state' => 'green', 'message' => 'Fetched', 'updatedAt' => gmdate('c')],
                'data' => $fresh['data'],
            ];
        }

        if ($cached !== null && is_array($cached['payload'])) {
            return [
                'status' => [
                    'state' => 'amber',
                    'message' => 'Halo stale cache: ' . $this->safeError((string) ($fresh['error'] ?? 'Fetch failed')),
                    'updatedAt' => $this->toIso($cached['updated_at']),
                ],
                'data' => $cached['payload'],
            ];
        }

        return [
            'status' => [
                'state' => 'red',
                'message' => 'Halo fetch failed: ' . $this->safeError((string) ($fresh['error'] ?? 'Fetch failed')),
                'updatedAt' => null,
            ],
            'data' => null,
        ];
    }

    private function parseList(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: [])));
    }

    private function safeError(string $message): string
    {
        $msg = preg_replace('/[^a-zA-Z0-9 .:_\-\/]/', '', trim($message));
        return $msg !== '' ? $msg : 'Unavailable';
    }

    private function ageSeconds(?string $mysqlDate): ?int
    {
        if ($mysqlDate === null || $mysqlDate === '') {
            return null;
        }
        $ts = strtotime($mysqlDate . ' UTC');
        if ($ts === false) {
            return null;
        }

        return max(0, time() - $ts);
    }

    private function toIso(?string $mysqlDate): ?string
    {
        if ($mysqlDate === null || $mysqlDate === '') {
            return null;
        }

        $ts = strtotime($mysqlDate . ' UTC');
        return $ts === false ? null : gmdate('c', $ts);
    }
}
