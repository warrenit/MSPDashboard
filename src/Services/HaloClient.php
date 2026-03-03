<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\CacheRepo;
use App\Core\Crypto;
use App\Core\SettingsRepo;

final class HaloClient
{
    public function __construct(
        private readonly SettingsRepo $settings,
        private readonly CacheRepo $cacheRepo
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->get('halo_enabled', '0') === '1';
    }

    public function hasCredentials(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled() && $this->hasCredentials();
    }

    public function testConnection(): array
    {
        try {
            $tokenResult = $this->getAccessTokenResult();
            if (!$tokenResult['ok']) {
                return [
                    'ok' => false,
                    'message' => 'Token request failed: HTTP ' . (int) $tokenResult['http_status'] . ' - ' . $this->truncate($tokenResult['response_preview'] ?? ''),
                    'http_status' => (int) $tokenResult['http_status'],
                ];
            }

            $path = (string) $this->settings->get('halo_test_path', '/tickets');
            $result = $this->request('GET', $path, ['page_size' => 1, 'page_no' => 1], (string) $tokenResult['access_token']);
            if ($result['ok']) {
                return ['ok' => true, 'message' => 'Connected to Halo successfully.', 'http_status' => $result['status']];
            }

            return [
                'ok' => false,
                'message' => 'API test failed: HTTP ' . (int) $result['status'] . ' - ' . $this->truncate((string) ($result['body'] ?? '')),
                'http_status' => (int) $result['status'],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection failed: ' . $this->safeError($e->getMessage()), 'http_status' => 0];
        }
    }

    public function diagnosticRequest(string $relativePath, string $queryString = ''): array
    {
        $token = $this->getAccessToken();
        parse_str($queryString, $query);
        $res = $this->request('GET', $relativePath, is_array($query) ? $query : [], $token);
        $preview = '';
        if (is_array($res['json'])) {
            $preview = (string) json_encode($res['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            $preview = (string) $res['body'];
        }

        $preview = mb_substr($preview, 0, 2000);

        return [
            'ok' => $res['ok'],
            'status' => $res['status'],
            'preview' => $preview,
            'error' => $res['ok'] ? null : $this->safeError($res['error']),
        ];
    }

    public function fetchDashboardData(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Halo not configured', 'data' => null];
        }

        try {
            $token = $this->getAccessToken();
            $tickets = $this->fetchTickets($token);
            if ($tickets === []) {
                return ['ok' => true, 'error' => null, 'data' => $this->emptyMetrics()];
            }

            $data = $this->buildMetricsFromTickets($tickets);
            return ['ok' => true, 'error' => null, 'data' => $data];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->safeError($e->getMessage()), 'data' => null];
        }
    }

    private function getAccessToken(): string
    {
        $result = $this->getAccessTokenResult();
        if (!$result['ok']) {
            throw new \RuntimeException('Token request failed: HTTP ' . (int) $result['http_status'] . ' - ' . $this->truncate((string) ($result['response_preview'] ?? '')));
        }

        return (string) $result['access_token'];
    }

    private function getAccessTokenResult(): array
    {
        $tokenCacheKey = hash('sha256', $this->authBaseUrl() . '|' . $this->clientId());
        $cached = $this->cacheRepo->get('halo_token');
        if ($cached !== null && isset($cached['payload']['access_token'], $cached['payload']['expires_at'], $cached['payload']['token_cache_key'])) {
            $expiresAt = strtotime((string) $cached['payload']['expires_at']);
            if ((string) $cached['payload']['token_cache_key'] === $tokenCacheKey && $expiresAt !== false && $expiresAt > time() + 60) {
                return ['ok' => true, 'access_token' => (string) $cached['payload']['access_token'], 'http_status' => 200, 'response_preview' => ''];
            }
        }

        $result = $this->requestToken('/token');
        if (!$result['ok']) {
            return [
                'ok' => false,
                'access_token' => '',
                'http_status' => (int) $result['status'],
                'response_preview' => $this->truncate((string) ($result['body'] ?? '')),
            ];
        }

        $token = (string) $result['json']['access_token'];
        $expiresIn = max(300, (int) ($result['json']['expires_in'] ?? 3600));
        $expiresAt = gmdate('c', time() + $expiresIn);

        $this->cacheRepo->upsert('halo_token', [
            'access_token' => $token,
            'expires_at' => $expiresAt,
            'token_cache_key' => $tokenCacheKey,
        ], 'ok', null);

        return ['ok' => true, 'access_token' => $token, 'http_status' => (int) $result['status'], 'response_preview' => ''];
    }

    private function requestToken(string $path): array
    {
        $url = rtrim($this->authBaseUrl(), '/') . '/' . ltrim($path, '/');
        $basic = base64_encode($this->clientId() . ':' . $this->clientSecret());

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'MSPDashboard-Halo/1.0',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'scope' => 'all',
            ]),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . $basic,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        if (!is_string($body)) {
            $error = curl_error($ch) ?: 'Token request failed';
            curl_close($ch);
            return ['ok' => false, 'status' => 0, 'json' => null, 'body' => $error, 'error' => $error];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($body, true);
        if ($status >= 200 && $status < 300 && is_array($json) && isset($json['access_token'])) {
            return ['ok' => true, 'status' => $status, 'json' => $json, 'body' => $body, 'error' => null];
        }

        return ['ok' => false, 'status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $body, 'error' => 'Token HTTP ' . $status];
    }

    private function fetchTickets(string $token): array
    {
        $pathCandidates = [
            (string) $this->settings->get('halo_tickets_path', '/tickets'),
            '/Tickets',
        ];

        $maxPages = max(1, min(10, (int) $this->settings->get('halo_max_pages', '3')));
        $pageSize = max(50, min(300, (int) $this->settings->get('halo_page_size', '200')));

        foreach ($pathCandidates as $path) {
            $all = [];
            $worked = false;
            for ($page = 1; $page <= $maxPages; $page++) {
                $res = $this->request('GET', $path, ['page_no' => $page, 'page_size' => $pageSize], $token);
                if (!$res['ok']) {
                    if ($page === 1) {
                        $worked = false;
                    }
                    break;
                }

                $worked = true;
                $rows = $this->extractRows($res['json']);
                if ($rows === []) {
                    break;
                }
                foreach ($rows as $row) {
                    $all[] = $row;
                }

                if (count($rows) < $pageSize) {
                    break;
                }
            }

            if ($worked) {
                return $all;
            }
        }

        throw new \RuntimeException('Unable to fetch tickets from Halo. Use Halo tester to confirm endpoint path and paging.');
    }

    private function request(string $method, string $relativePath, array $query, string $token): array
    {
        $url = rtrim($this->resourceBaseUrl(), '/') . '/' . ltrim($relativePath, '/');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'MSPDashboard-Halo/1.0',
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        if (!is_string($body)) {
            $error = curl_error($ch) ?: 'Request failed';
            curl_close($ch);
            return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => $error];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($body, true);
        $ok = $status >= 200 && $status < 300;

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => $body,
            'json' => is_array($json) ? $json : null,
            'error' => $ok ? null : 'HTTP ' . $status,
        ];
    }

    private function buildMetricsFromTickets(array $tickets): array
    {
        $waitingStatuses = $this->normalizeList((string) $this->settings->get('status_waiting_on_customer', ''));
        $respondedStatuses = $this->normalizeList((string) $this->settings->get('status_customer_responded', ''));
        $importantTypes = $this->normalizeList((string) $this->settings->get('important_ticket_types', ''));
        $projectTypes = $this->normalizeList((string) $this->settings->get('project_type_list', 'project'));
        $dueSoonMinutes = max(10, (int) $this->settings->get('sla_due_soon_minutes', '120'));
        $selectedAgents = $this->normalizeList((string) $this->settings->get('agent_names', ''));

        $now = time();
        $weekStart = strtotime('monday this week 00:00:00');
        $todayStart = strtotime('today 00:00:00');

        $totalOpen = 0;
        $unassigned = 0;
        $waiting = 0;
        $responded = 0;
        $important = 0;
        $projectOpen = 0;
        $slaOverdue = 0;
        $slaDueSoon = 0;
        $oldestOpenAge = null;
        $openByAgent = [];
        $closedByAgent = [];
        $firstResponseSamples = [];

        foreach ($tickets as $ticket) {
            if (!is_array($ticket)) {
                continue;
            }

            $status = $this->lower($this->ticketStatus($ticket));
            $type = $this->lower($this->ticketType($ticket));
            $agent = $this->ticketAgent($ticket);
            $isOpen = $this->isOpen($ticket);
            $isClosed = !$isOpen;

            if ($isOpen) {
                $totalOpen++;
                $age = $this->ticketAgeSeconds($ticket, $now);
                if ($age !== null) {
                    $oldestOpenAge = $oldestOpenAge === null ? $age : max($oldestOpenAge, $age);
                }

                if ($this->isUnassigned($ticket, $agent)) {
                    $unassigned++;
                }

                if ($waitingStatuses !== [] && in_array($status, $waitingStatuses, true)) {
                    $waiting++;
                }
                if ($respondedStatuses !== [] && in_array($status, $respondedStatuses, true)) {
                    $responded++;
                }
                if ($importantTypes !== [] && in_array($type, $importantTypes, true)) {
                    $important++;
                }

                if ($this->isProjectType($type, $projectTypes)) {
                    $projectOpen++;
                }

                $slaDueTs = $this->slaDueTimestamp($ticket);
                if ($slaDueTs !== null) {
                    if ($slaDueTs < $now) {
                        $slaOverdue++;
                    } elseif ($slaDueTs <= $now + ($dueSoonMinutes * 60)) {
                        $slaDueSoon++;
                    }
                }

                $agentKey = $agent !== '' ? $agent : 'Unassigned';
                if ($selectedAgents === [] || in_array($this->lower($agentKey), $selectedAgents, true)) {
                    $openByAgent[$agentKey] = ($openByAgent[$agentKey] ?? 0) + 1;
                }
            }

            if ($isClosed) {
                $closedTs = $this->closedTimestamp($ticket);
                if ($closedTs !== null && $closedTs >= $weekStart) {
                    $agentKey = $agent !== '' ? $agent : 'Unassigned';
                    if ($selectedAgents === [] || in_array($this->lower($agentKey), $selectedAgents, true)) {
                        $closedByAgent[$agentKey] = ($closedByAgent[$agentKey] ?? 0) + 1;
                    }
                }
            }

            $frMinutes = $this->firstResponseMinutes($ticket);
            $createdTs = $this->createdTimestamp($ticket);
            if ($frMinutes !== null && $createdTs !== null && $createdTs >= $todayStart) {
                $firstResponseSamples[] = $frMinutes;
            }
        }

        $avgFirstResponse = null;
        if ($firstResponseSamples !== []) {
            $avgFirstResponse = array_sum($firstResponseSamples) / count($firstResponseSamples);
        }

        return [
            'tiles' => [
                'unassignedCount' => $unassigned,
                'importantAlertsCount' => $important,
                'totalOpenCount' => $totalOpen,
                'waitingOnCustomerCount' => $waiting,
                'customerRespondedCount' => $responded,
                'slaDueSoonCount' => $slaDueSoon,
                'slaOverdueCount' => $slaOverdue,
                'projectOpenCount' => $projectOpen,
                'oldestOpenTicketAgeSeconds' => $oldestOpenAge,
                'avgFirstResponseMinutesToday' => $avgFirstResponse,
            ],
            'charts' => [
                'openByAgent' => $this->mapChart($openByAgent),
                'closedThisWeekByAgent' => $this->mapChart($closedByAgent),
            ],
        ];
    }

    private function emptyMetrics(): array
    {
        return [
            'tiles' => [
                'unassignedCount' => 0,
                'importantAlertsCount' => 0,
                'totalOpenCount' => 0,
                'waitingOnCustomerCount' => 0,
                'customerRespondedCount' => 0,
                'slaDueSoonCount' => 0,
                'slaOverdueCount' => 0,
                'projectOpenCount' => 0,
                'oldestOpenTicketAgeSeconds' => null,
                'avgFirstResponseMinutesToday' => null,
            ],
            'charts' => [
                'openByAgent' => [],
                'closedThisWeekByAgent' => [],
            ],
        ];
    }

    private function mapChart(array $series): array
    {
        arsort($series);
        $out = [];
        foreach ($series as $agent => $count) {
            $out[] = ['agent' => $agent, 'count' => (int) $count];
        }
        return $out;
    }

    private function extractRows(?array $json): array
    {
        if ($json === null) {
            return [];
        }

        if (array_is_list($json)) {
            return $json;
        }

        foreach (['tickets', 'Tickets', 'data', 'Data', 'results', 'Results'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                return array_is_list($json[$key]) ? $json[$key] : [];
            }
        }

        return [];
    }

    private function ticketStatus(array $ticket): string
    {
        foreach (['status_name', 'status', 'statusName', 'ticketstatus', 'ticketStatus'] as $key) {
            if (isset($ticket[$key]) && is_string($ticket[$key])) {
                return $ticket[$key];
            }
        }

        if (isset($ticket['status']) && is_array($ticket['status'])) {
            foreach (['name', 'status', 'status_name'] as $key) {
                if (isset($ticket['status'][$key]) && is_string($ticket['status'][$key])) {
                    return $ticket['status'][$key];
                }
            }
        }

        return '';
    }

    private function ticketType(array $ticket): string
    {
        foreach (['ticket_type', 'type', 'type_name', 'ticketType', 'tickettype'] as $key) {
            if (isset($ticket[$key]) && is_string($ticket[$key])) {
                return $ticket[$key];
            }
        }

        if (isset($ticket['type']) && is_array($ticket['type'])) {
            foreach (['name', 'type_name'] as $k) {
                if (isset($ticket['type'][$k]) && is_string($ticket['type'][$k])) {
                    return $ticket['type'][$k];
                }
            }
        }

        return '';
    }

    private function ticketAgent(array $ticket): string
    {
        foreach (['assigned_agent', 'agent_name', 'assignedTo', 'assignedto'] as $key) {
            if (isset($ticket[$key]) && is_string($ticket[$key])) {
                return trim($ticket[$key]);
            }
        }

        foreach (['agent', 'assigned_agent', 'assignedTo'] as $key) {
            if (isset($ticket[$key]) && is_array($ticket[$key])) {
                foreach (['name', 'full_name', 'agent_name'] as $k) {
                    if (isset($ticket[$key][$k]) && is_string($ticket[$key][$k])) {
                        return trim($ticket[$key][$k]);
                    }
                }
            }
        }

        return '';
    }

    private function isOpen(array $ticket): bool
    {
        if (isset($ticket['is_closed'])) {
            return !$this->toBool($ticket['is_closed']);
        }
        if (isset($ticket['closed'])) {
            return !$this->toBool($ticket['closed']);
        }
        if ($this->closedTimestamp($ticket) !== null) {
            return false;
        }

        $status = $this->lower($this->ticketStatus($ticket));
        return !str_contains($status, 'closed');
    }

    private function isUnassigned(array $ticket, string $agent): bool
    {
        if ($agent === '') {
            return true;
        }

        foreach (['assigned_agent_id', 'agent_id', 'assignedto_id'] as $idKey) {
            if (isset($ticket[$idKey]) && (int) $ticket[$idKey] <= 0) {
                return true;
            }
        }

        return false;
    }

    private function isProjectType(string $typeLower, array $projectTypes): bool
    {
        if ($typeLower === '') {
            return false;
        }

        if (in_array($typeLower, $projectTypes, true)) {
            return true;
        }

        return str_contains($typeLower, 'project');
    }

    private function ticketAgeSeconds(array $ticket, int $now): ?int
    {
        $created = $this->createdTimestamp($ticket);
        if ($created === null) {
            return null;
        }
        return max(0, $now - $created);
    }

    private function createdTimestamp(array $ticket): ?int
    {
        foreach (['datecreated', 'created_at', 'created', 'dateCreated'] as $key) {
            if (isset($ticket[$key]) && is_string($ticket[$key])) {
                $ts = strtotime($ticket[$key]);
                if ($ts !== false) {
                    return $ts;
                }
            }
        }
        return null;
    }

    private function closedTimestamp(array $ticket): ?int
    {
        foreach (['dateclosed', 'closed_at', 'dateClosed', 'closedDate'] as $key) {
            if (isset($ticket[$key]) && is_string($ticket[$key])) {
                $ts = strtotime($ticket[$key]);
                if ($ts !== false) {
                    return $ts;
                }
            }
        }
        return null;
    }

    private function slaDueTimestamp(array $ticket): ?int
    {
        foreach (['sla_due', 'sladue', 'sla_due_date', 'next_sla_date', 'nextSlaDate'] as $key) {
            if (isset($ticket[$key]) && is_string($ticket[$key])) {
                $ts = strtotime($ticket[$key]);
                if ($ts !== false) {
                    return $ts;
                }
            }
        }
        return null;
    }

    private function firstResponseMinutes(array $ticket): ?float
    {
        foreach (['first_response_minutes', 'firstresponseminutes', 'firstResponseMinutes'] as $key) {
            if (isset($ticket[$key]) && is_numeric($ticket[$key])) {
                return (float) $ticket[$key];
            }
        }
        return null;
    }

    private function normalizeList(string $value): array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: [])));
        return array_map(fn(string $v): string => $this->lower($v), $parts);
    }

    private function lower(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }
        $str = $this->lower((string) $value);
        return in_array($str, ['1', 'true', 'yes', 'y'], true);
    }

    private function safeError(?string $message): string
    {
        $msg = preg_replace('/[^a-zA-Z0-9 .:_\-\/]/', '', trim((string) $message));
        return $msg !== '' ? $msg : 'Unavailable';
    }

    private function truncate(string $value, int $max = 500): string
    {
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $clean = trim((string) $sanitized);
        if ($clean === '') {
            return 'No response body';
        }

        return mb_substr($clean, 0, $max);
    }

    private function clientId(): string
    {
        return trim((string) $this->settings->get('halo_client_id', ''));
    }

    private function clientSecret(): string
    {
        $enc = trim((string) $this->settings->get('halo_client_secret_enc', ''));
        if ($enc === '') {
            return '';
        }

        try {
            return trim(Crypto::decryptString($enc));
        } catch (\Throwable) {
            return '';
        }
    }

    private function resourceBaseUrl(): string
    {
        $url = trim((string) $this->settings->get('halo_resource_base_url', 'https://servicedesk.ilkleyitservices.co.uk/api'));
        return $url !== '' ? $url : 'https://servicedesk.ilkleyitservices.co.uk/api';
    }

    private function authBaseUrl(): string
    {
        $url = trim((string) $this->settings->get('halo_auth_base_url', 'https://servicedesk.ilkleyitservices.co.uk/auth'));
        return $url !== '' ? $url : 'https://servicedesk.ilkleyitservices.co.uk/auth';
    }
}
