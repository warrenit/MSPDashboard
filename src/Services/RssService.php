<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\CacheRepo;
use App\Core\SettingsRepo;

final class RssService
{
    public function __construct(
        private readonly SettingsRepo $settings,
        private readonly CacheRepo $cacheRepo
    ) {
    }

    public function getTickerData(): array
    {
        $rssEnabled = ((string) $this->settings->get('rss_enabled', '0')) === '1';
        if (!$rssEnabled) {
            return [
                'status' => ['state' => 'grey', 'message' => 'Disabled', 'updatedAt' => null],
                'ticker' => ['enabled' => false, 'items' => []],
            ];
        }

        $feeds = $this->parseList((string) $this->settings->get('rss_feed_urls', ''));
        if ($feeds === []) {
            return [
                'status' => ['state' => 'grey', 'message' => 'No feeds configured', 'updatedAt' => null],
                'ticker' => ['enabled' => true, 'items' => []],
            ];
        }

        $cacheInterval = max(60, (int) $this->settings->get('rss_cache_interval_sec', '900'));
        $cached = $this->cacheRepo->get('rss');
        $cacheAge = $this->ageSeconds($cached['updated_at'] ?? null);
        $hasFreshCache = $cached !== null && $cacheAge !== null && $cacheAge <= $cacheInterval;

        if ($hasFreshCache) {
            return [
                'status' => ['state' => 'green', 'message' => 'OK (cached)', 'updatedAt' => $this->toIso($cached['updated_at'])],
                'ticker' => ['enabled' => true, 'items' => $this->sanitizeItems($cached['payload']['items'] ?? [])],
            ];
        }

        $fetch = $this->fetchFeeds($feeds);
        if ($fetch['ok']) {
            $items = $this->sanitizeItems($fetch['items']);
            $this->cacheRepo->upsert('rss', ['items' => $items], 'ok', null);
            return [
                'status' => ['state' => 'green', 'message' => 'Fetched', 'updatedAt' => gmdate('c')],
                'ticker' => ['enabled' => true, 'items' => $items],
            ];
        }

        if ($cached !== null) {
            return [
                'status' => [
                    'state' => 'amber',
                    'message' => 'Stale cache: ' . $this->safeError($fetch['error']),
                    'updatedAt' => $this->toIso($cached['updated_at']),
                ],
                'ticker' => ['enabled' => true, 'items' => $this->sanitizeItems($cached['payload']['items'] ?? [])],
            ];
        }

        return [
            'status' => [
                'state' => 'red',
                'message' => 'Fetch failed: ' . $this->safeError($fetch['error']),
                'updatedAt' => null,
            ],
            'ticker' => ['enabled' => true, 'items' => []],
        ];
    }

    private function fetchFeeds(array $feeds): array
    {
        $items = [];
        $errors = [];

        foreach ($feeds as $url) {
            $res = $this->fetchUrl($url);
            if (!$res['ok']) {
                $errors[] = $res['error'];
                continue;
            }

            $parsed = $this->parseXmlFeed($res['body']);
            if (!$parsed['ok']) {
                $errors[] = $parsed['error'];
                continue;
            }

            foreach ($parsed['items'] as $item) {
                $items[] = $item;
                if (count($items) >= 20) {
                    break 2;
                }
            }
        }

        if ($items !== []) {
            return ['ok' => true, 'items' => $items, 'error' => null];
        }

        return ['ok' => false, 'items' => [], 'error' => $errors[0] ?? 'No headlines available'];
    }

    private function fetchUrl(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'MSPDashboard/2.1 (+https://localhost)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        if (!is_string($body)) {
            $error = curl_error($ch) ?: 'RSS request failed';
            curl_close($ch);
            return ['ok' => false, 'body' => '', 'error' => $error];
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 400) {
            return ['ok' => false, 'body' => '', 'error' => 'HTTP ' . $httpCode];
        }

        return ['ok' => true, 'body' => $body, 'error' => null];
    }

    private function parseXmlFeed(string $xmlString): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        if ($xml === false) {
            libxml_clear_errors();
            return ['ok' => false, 'items' => [], 'error' => 'Invalid XML'];
        }

        $items = [];

        if (isset($xml->channel->item)) { // RSS
            foreach ($xml->channel->item as $item) {
                $title = trim((string) ($item->title ?? ''));
                $link = trim((string) ($item->link ?? ''));
                if ($title !== '' && $link !== '') {
                    $items[] = ['title' => $title, 'link' => $link];
                }
            }
        } elseif (isset($xml->entry)) { // Atom
            foreach ($xml->entry as $entry) {
                $title = trim((string) ($entry->title ?? ''));
                $link = '';
                foreach ($entry->link as $entryLink) {
                    $attrs = $entryLink->attributes();
                    if (isset($attrs['href'])) {
                        $link = trim((string) $attrs['href']);
                        break;
                    }
                }
                if ($title !== '' && $link !== '') {
                    $items[] = ['title' => $title, 'link' => $link];
                }
            }
        }

        if ($items === []) {
            return ['ok' => false, 'items' => [], 'error' => 'No items found'];
        }

        return ['ok' => true, 'items' => array_slice($items, 0, 20), 'error' => null];
    }

    private function sanitizeItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $link = trim((string) ($item['link'] ?? ''));
            if ($title === '' || $link === '') {
                continue;
            }
            $out[] = ['title' => $title, 'link' => $link];
            if (count($out) >= 20) {
                break;
            }
        }

        return $out;
    }

    private function parseList(string $value): array
    {
        $list = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: [])));
        return array_values(array_filter($list, static fn (string $v): bool => filter_var($v, FILTER_VALIDATE_URL) !== false));
    }

    private function safeError(?string $message): string
    {
        $msg = trim((string) $message);
        if ($msg === '') {
            return 'Unavailable';
        }

        return preg_replace('/[^a-zA-Z0-9 .:_\-]/', '', $msg) ?: 'Unavailable';
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
