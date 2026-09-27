<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Support;

use App\Domain\DeveloperPlatform\Models\ApiKey;

/**
 * Request-scoped holder for the authenticated ApiKey — mirrors
 * TenantContext's own "resolved once per request, read everywhere"
 * pattern. Deliberately separate from Sanctum's own $request->user()
 * (a Developer Application's API key is NOT a User at all — see
 * docs/development/b18-inspection-findings.md).
 */
final class ApiKeyContext
{
    private ?ApiKey $apiKey = null;

    public function set(ApiKey $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function get(): ApiKey
    {
        return $this->apiKey ?? throw new \RuntimeException('No API key has been authenticated for this request.');
    }

    public function isSet(): bool
    {
        return $this->apiKey !== null;
    }
}
