<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiCacheRepository;
use App\Core\EncryptionService;
use App\Core\SettingsRepository;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class DashboardService
{
    private const CACHE_KEY = 'dashboard_v1';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ApiCacheRepository $cache,
        private readonly EncryptionService $encryption
    ) {
    }

    public function getDashboardPayload(): array
    {
        $cacheInterval = max(10, (int) ($this->settings->get('cache_interval', '60') ?? '60'));
        $existingCache = $this->cache->get(self::CACHE_KEY);

        if ($existingCache && $existingCache['is_fresh']) {
            $cachedPayload = $existingCache['value'];
            $cachedPayload['meta']['cache']['source'] = 'fresh-cache';
            return $cachedPayload;
        }

        try {
            $haloClient = $this->createHaloClient();
            $count = $haloClient->fetchUnassignedCount();

            $payload = [
                'apiStatus' => [
                    'halo' => [
                        'state' => 'green',
                        'message' => 'Halo API healthy',
                    ],
                ],
                'tiles' => [
                    'unassignedCount' => $count,
                ],
                'updatedAt' => [
                    'halo' => $this->utcNow(),
                    'dashboard' => $this->utcNow(),
                ],
                'meta' => [
                    'cache' => [
                        'source' => 'live',
                        'maxAgeSeconds' => $cacheInterval,
                    ],
                ],
            ];

            $this->cache->put(self::CACHE_KEY, $payload, $cacheInterval);
            return $payload;
        } catch (Throwable $e) {
            if ($existingCache) {
                $cachedPayload = $existingCache['value'];
                $cachedPayload['apiStatus']['halo'] = [
                    'state' => 'amber',
                    'message' => 'Using cached Halo data: ' . $this->safeError($e),
                ];
                $cachedPayload['meta']['cache']['source'] = 'stale-cache';
                $cachedPayload['meta']['cache']['maxAgeSeconds'] = $cacheInterval;
                $cachedPayload['updatedAt']['dashboard'] = $this->utcNow();

                return $cachedPayload;
            }

            return [
                'apiStatus' => [
                    'halo' => [
                        'state' => 'red',
                        'message' => $this->safeError($e),
                    ],
                ],
                'tiles' => [
                    'unassignedCount' => null,
                ],
                'updatedAt' => [
                    'halo' => null,
                    'dashboard' => $this->utcNow(),
                ],
                'meta' => [
                    'cache' => [
                        'source' => 'none',
                        'maxAgeSeconds' => $cacheInterval,
                    ],
                ],
            ];
        }
    }

    private function createHaloClient(): HaloClient
    {
        $encryptedSecret = (string) $this->settings->get('halo_client_secret', '');
        $clientSecret = $encryptedSecret !== '' ? $this->encryption->decrypt($encryptedSecret) : '';

        return new HaloClient(
            (string) $this->settings->get('halo_resource_base_url', ''),
            (string) $this->settings->get('halo_auth_base_url', ''),
            (string) $this->settings->get('halo_tenant', ''),
            (string) $this->settings->get('halo_client_id', ''),
            $clientSecret
        );
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function safeError(Throwable $e): string
    {
        $message = $e->getMessage();

        return preg_replace('/(client_secret=)([^&\s]+)/i', '$1[REDACTED]', $message) ?: 'Unknown error';
    }
}
