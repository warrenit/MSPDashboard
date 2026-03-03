<?php

declare(strict_types=1);

namespace App\Services;

final class HaloClient
{
    public function __construct(private readonly array $config)
    {
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
