<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Exceptions\TenantContextMissingException;

/**
 * Request/job-scoped tenant context container.
 *
 * Implements ADR-001 Layer 2. This is the ONLY authoritative source of
 * "which tenant is this request/job operating as" anywhere in the
 * application. It is populated exclusively by server-trusted resolution
 * (ResolveTenantContext middleware for HTTP, or explicit constructor
 * injection for queued jobs — see App\Domain\Tenancy\Support\TenantAware).
 *
 * It is NEVER populated directly from client input (route params, query
 * strings, request bodies, or headers) — see ADR-001 §8 Layer 1.
 *
 * Bound as a singleton per request in AppServiceProvider. For queue jobs,
 * a fresh instance is rebuilt from the job's own stored store_id (ADR-001
 * §8 Layer 6) — never inherited from the dispatching request's container
 * state, since jobs may execute in a different process entirely.
 */
final class TenantContext
{
    private ?int $storeId = null;

    private bool $platform = false;

    private bool $impersonation = false;

    private ?int $actingSuperAdminId = null;

    /**
     * Resolve the context to a specific tenant. Called only by
     * ResolveTenantContext middleware or trusted job bootstrapping code.
     */
    public function resolveToStore(int $storeId): void
    {
        $this->storeId = $storeId;
        $this->platform = false;
    }

    /**
     * Resolve the context to platform-level (no single tenant).
     * Used for: Super Admin cross-tenant screens (ADR-001 Layer 7) and
     * explicit platform_system operations (ADR-001 Layer 9). Both callers
     * must additionally be permission-checked and audit-logged by the
     * caller — this class only tracks the resolved *shape* of the context,
     * it does not perform authorization itself.
     */
    public function resolveToPlatform(): void
    {
        $this->storeId = null;
        $this->platform = true;
    }

    public function markImpersonation(int $actingSuperAdminId, int $storeId): void
    {
        $this->impersonation = true;
        $this->actingSuperAdminId = $actingSuperAdminId;
        $this->resolveToStore($storeId);
    }

    public function hasStore(): bool
    {
        return $this->storeId !== null;
    }

    /**
     * @throws TenantContextMissingException when no tenant has been
     *         resolved yet — fail loudly rather than silently returning
     *         null and letting a global scope accidentally match "no
     *         filter at all".
     */
    public function storeId(): int
    {
        if ($this->storeId === null) {
            throw new TenantContextMissingException(
                'Tenant context was read before it was resolved. '.
                'A tenant-scoped query must never run without a resolved TenantContext.'
            );
        }

        return $this->storeId;
    }

    public function isPlatform(): bool
    {
        return $this->platform;
    }

    public function isImpersonating(): bool
    {
        return $this->impersonation;
    }

    public function actingSuperAdminId(): ?int
    {
        return $this->actingSuperAdminId;
    }
}
