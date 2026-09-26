<?php

declare(strict_types=1);

namespace App\Domain\Domains\Services;

use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Tenancy\Models\Store;

/**
 * Module 19 §3/§19-21 "Architectural Position / Storefront Domain
 * Resolution / Security Boundary / Host Header Security" —
 * Non-Negotiable, the single most important class in this milestone.
 *
 * Deliberately bypasses BelongsToTenant's global scope
 * (`withoutTenantScope()`) — this is the ONE legitimate reason to do
 * so: by definition, NO tenant context exists yet when resolving an
 * anonymous request's Host header. Never used for any other purpose.
 *
 * A raw Host header is meaningless input on its own; it becomes an
 * authoritative tenant mapping ONLY after: normalize -> look up in the
 * verified Domain registry -> confirm the row's status ACTUALLY
 * resolves traffic (Active only — Pending/VerificationRequired/
 * Verified/Suspended/Disabled/Removed all return null here, never a
 * fallback to some other store).
 */
final class DomainResolverService
{
    public function __construct(private readonly HostnameNormalizer $normalizer) {}

    public function resolveHost(string $rawHost): ?Store
    {
        try {
            $normalized = $this->normalizer->normalize($rawHost);
        } catch (\App\Domain\Domains\Exceptions\InvalidHostnameException) {
            return null; // a malformed Host header resolves to nothing — never guessed at, never falls back to another store
        }

        $domain = Domain::query()->withoutTenantScope()
            ->where('normalized_hostname', $normalized)
            ->first();

        if ($domain === null || ! $domain->status->resolvesTraffic()) {
            return null;
        }

        return Store::query()->find($domain->store_id);
    }

    /** Module 19 §22/B13 Integration — the store's verified PRIMARY domain, or null if none is yet Active+primary (a brand-new store always has one via its auto-created platform subdomain). */
    public function primaryDomainFor(Store $store): ?Domain
    {
        return Domain::query()->withoutTenantScope()
            ->where('store_id', $store->id)
            ->where('is_primary', true)
            ->where('status', DomainStatus::Active->value)
            ->first();
    }
}
