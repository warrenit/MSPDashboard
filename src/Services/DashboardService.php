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

        $debug = null;
        if (isset($halo['data']['ticketProjection']) && is_array($halo['data']['ticketProjection'])) {
            $mapped = $this->applyMappedCounts($tiles, $halo['data']['ticketProjection']);
            $tiles = $mapped['tiles'];

            if ($mapped['mappingMissing']) {
                if (in_array($halo['status']['state'] ?? 'grey', ['green', 'amber'], true)) {
                    $halo['status']['state'] = 'amber';
                    $halo['status']['message'] = 'Missing open/status mappings';
                }
            }

            if (isset($_GET['admin_debug']) && $_GET['admin_debug'] === '1') {
                $debug = $mapped['debug'];
            }
        }

        $payload = [
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

        if ($debug !== null) {
            $payload['debug'] = $debug;
        }

        return $payload;
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

    private function applyMappedCounts(array $tiles, array $ticketProjection): array
    {
        $scopeTypeIds = $this->parseIntList((string) $this->settings->get('scope_tickettype_ids', ''));
        $openStatusIds = $this->parseIntList((string) $this->settings->get('open_status_ids', ''));
        $importantTypeIds = $this->parseIntList((string) $this->settings->get('important_tickettype_ids', ''));

        $mappingMissing = $scopeTypeIds === [] || $openStatusIds === [];
        if ($mappingMissing) {
            $tiles['totalOpenCount'] = 0;
            $tiles['unassignedCount'] = 0;
            $tiles['importantAlertsCount'] = 0;

            return [
                'tiles' => $tiles,
                'mappingMissing' => true,
                'debug' => [
                    'agentAssignedBucket' => ['agent_id_0' => 0, 'agent_id_gt0' => 0],
                    'sampleUnassigned' => [],
                    'sampleAssigned' => [],
                ],
            ];
        }

        $scopeTypeLookup = array_fill_keys($scopeTypeIds, true);
        $openStatusLookup = array_fill_keys($openStatusIds, true);
        $importantTypeLookup = array_fill_keys($importantTypeIds, true);

        $totalOpen = 0;
        $unassigned = 0;
        $important = 0;

        $bucket0 = 0;
        $bucketGt0 = 0;
        $sampleUnassigned = [];
        $sampleAssigned = [];

        foreach ($ticketProjection as $ticket) {
            if (!is_array($ticket)) {
                continue;
            }

            $statusId = $this->toInt($ticket['status_id'] ?? null);
            $ticketTypeId = $this->toInt($ticket['tickettype_id'] ?? null);
            $agentId = $this->toInt($ticket['agent_id'] ?? null);
            $ticketId = $this->toInt($ticket['id'] ?? null);

            if ($statusId === null || $ticketTypeId === null || !isset($openStatusLookup[$statusId]) || !isset($scopeTypeLookup[$ticketTypeId])) {
                continue;
            }

            $totalOpen++;
            if ($agentId === 0) {
                $unassigned++;
                $bucket0++;
                if ($ticketId !== null && count($sampleUnassigned) < 5) {
                    $sampleUnassigned[] = $ticketId;
                }

                if ($importantTypeLookup !== [] && isset($importantTypeLookup[$ticketTypeId])) {
                    $important++;
                }
            } elseif ($agentId !== null && $agentId > 0) {
                $bucketGt0++;
                if ($ticketId !== null && count($sampleAssigned) < 5) {
                    $sampleAssigned[] = $ticketId;
                }
            }
        }

        $tiles['totalOpenCount'] = $totalOpen;
        $tiles['unassignedCount'] = $unassigned;
        $tiles['importantAlertsCount'] = $important;

        return [
            'tiles' => $tiles,
            'mappingMissing' => false,
            'debug' => [
                'agentAssignedBucket' => ['agent_id_0' => $bucket0, 'agent_id_gt0' => $bucketGt0],
                'sampleUnassigned' => $sampleUnassigned,
                'sampleAssigned' => $sampleAssigned,
            ],
        ];
    }

    private function parseList(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: [])));
    }

    private function parseIntList(string $value): array
    {
        $out = [];
        foreach ($this->parseList($value) as $item) {
            if (is_numeric($item)) {
                $out[] = (int) $item;
            }
        }
        return array_values(array_unique($out));
    }

    private function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return null;
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
