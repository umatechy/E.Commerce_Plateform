<?php

declare(strict_types=1);

return [
    // ADR-001 — central place documenting the tenant-resolution contract.
    // No secrets or per-environment values live here; this is structural
    // configuration only.

    'cache_key_prefix' => 'tenant',

    // Models using App\Domain\Tenancy\Support\BelongsToTenant are treated
    // as tenant-owned; this list exists for tooling (e.g. a future
    // Artisan command that verifies every tenant-owned table has a
    // store_id column and a leading composite index, per ADR-003).
    'tenant_owned_models' => [
        \App\Domain\Identity\Models\Role::class,
        \App\Domain\Packages\Models\Subscription::class,
        \App\Domain\Events\Models\OutboxEvent::class,
        // Extended module-by-module as each domain is implemented.
    ],
];
