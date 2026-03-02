<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class HaloClient
{
    public function __construct(
        private readonly string $resourceBaseUrl,
        private readonly string $authBaseUrl,
        private readonly string $tenant,
        private readonly string $clientId,
        private readonly string $clientSecret
    ) {
    }

    public function fetchUnassignedCount(): int
    {
        $token = $this->fetchAccessToken();

        // TODO(halo-endpoint): replace this path and query with confirmed Halo endpoint for unassigned tickets.
        // Example placeholder only; keep response parsing aligned to the confirmed endpoint payload.
        $url = rtrim($this->resourceBaseUrl, '/') . '/api/reports/unassigned-count';

        [$status, $body] = $this->request('GET', $url, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);

        if ($status >= 400) {
            throw new RuntimeException('Halo resource request failed with HTTP ' . $status . '.');
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Halo resource response was not valid JSON.');
        }

        // TODO(halo-endpoint): adjust key mapping if endpoint returns a different shape.
        $count = $json['count'] ?? $json['unassignedCount'] ?? null;
        if (!is_numeric($count)) {
            throw new RuntimeException('Halo response did not include numeric unassigned count.');
        }

        return (int) $count;
    }

    private function fetchAccessToken(): string
    {
        $tokenUrl = rtrim($this->authBaseUrl, '/') . '/token';
        $postFields = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => $this->tenant,
        ]);

        [$status, $body] = $this->request('POST', $tokenUrl, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $postFields);

        if ($status >= 400) {
            throw new RuntimeException('Halo auth request failed with HTTP ' . $status . '.');
        }

        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['access_token'])) {
            throw new RuntimeException('Halo auth response missing access_token.');
        }

        return (string) $json['access_token'];
    }

    private function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $message = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL request failed: ' . $message);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $response];
    }
}
