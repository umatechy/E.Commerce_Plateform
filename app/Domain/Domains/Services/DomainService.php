<?php

declare(strict_types=1);

namespace App\Domain\Domains\Services;

use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Domains\Models\DomainType;
use App\Domain\Domains\Models\SslStatus;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY code path that creates a Domain or transitions its
 * status/is_primary — mirrors every other domain service's
 * "service-only writes" pattern.
 */
final class DomainService
{
    public function __construct(
        private readonly HostnameNormalizer $normalizer,
        private readonly DomainStateMachine $stateMachine,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /** Called once, additively, from StoreObserver for every new store — see docs/development/b14-inspection-findings.md. */
    public function createPlatformSubdomain(Store $store): Domain
    {
        $hostname = "{$store->slug}.".config('domains.platform_base_domain');

        return Domain::query()->create([
            'store_id' => $store->id,
            'hostname' => $hostname,
            'normalized_hostname' => $this->normalizer->normalize($hostname),
            'domain_type' => DomainType::PlatformSubdomain,
            'status' => DomainStatus::Active, // the platform itself controls this DNS zone — no external verification is possible or needed
            'is_primary' => true, // the only domain a brand-new store has
            'verified_at' => now(),
        ]);
    }

    /**
     * @throws \App\Domain\Domains\Exceptions\InvalidHostnameException
     * @throws \Illuminate\Database\QueryException (unique constraint — a hostname already claimed by any store)
     */
    public function addCustomDomain(Store $store, string $rawHostname): Domain
    {
        $normalized = $this->normalizer->normalize($rawHostname);

        return DB::transaction(function () use ($store, $rawHostname, $normalized) {
            $domain = Domain::query()->create([
                'store_id' => $store->id,
                'hostname' => $rawHostname,
                'normalized_hostname' => $normalized,
                'domain_type' => DomainType::CustomDomain,
                'status' => DomainStatus::Pending,
                'is_primary' => false,
            ]);

            $this->outbox->recordEventFor(
                $store->id,
                eventType: 'domain.added',
                payload: ['domain_id' => $domain->id],
                idempotencyKey: "domain:{$domain->id}:added",
            );

            return $domain;
        });
    }

    /** Module 19 §17 "Primary Domain" — only a Verified or Active domain is eligible for promotion. */
    public function activate(Domain $domain): Domain
    {
        $this->transitionTo($domain, DomainStatus::Active);

        return $domain->fresh();
    }

    public function suspend(Domain $domain): Domain
    {
        $this->transitionTo($domain, DomainStatus::Suspended);

        return $domain->fresh();
    }

    public function disable(Domain $domain): Domain
    {
        $this->transitionTo($domain, DomainStatus::Disabled);

        return $domain->fresh();
    }

    /**
     * Module 19 §17-18 "Primary Domain / Domain Switching" —
     * Architectural Decision: pessimistic row-locking transaction (see
     * inspection findings) — the correct tool for a genuine multi-row
     * invariant ("exactly one TRUE per store"), unlike the single-
     * counter atomic-UPDATE pattern used elsewhere in this codebase.
     *
     * @throws \App\Domain\Domains\Exceptions\DomainNotEligibleForPrimaryException
     * @throws \App\Domain\Domains\Exceptions\InvalidDomainStateTransitionException
     */
    public function setPrimary(Domain $domain): Domain
    {
        if (! in_array($domain->status, [DomainStatus::Verified, DomainStatus::Active], true)) {
            throw new \App\Domain\Domains\Exceptions\DomainNotEligibleForPrimaryException();
        }

        return DB::transaction(function () use ($domain) {
            Domain::query()->where('store_id', $domain->store_id)->lockForUpdate()->get();

            // Re-fetch the target domain's OWN row from WITHIN the lock —
            // the in-memory $domain passed into this method could be
            // stale relative to a concurrent write that landed between
            // the caller's original load and this transaction acquiring
            // the lock. Every decision below must be based on the
            // truly-current row, not a possibly-outdated one.
            $domain = Domain::query()->lockForUpdate()->findOrFail($domain->id);

            Domain::query()->where('store_id', $domain->store_id)->where('id', '!=', $domain->id)->update(['is_primary' => false]);

            if ($domain->status !== DomainStatus::Active) {
                $this->stateMachine->assertCanTransition($domain->status, DomainStatus::Active);
                $domain->update(['status' => DomainStatus::Active]);
            }

            $domain->update(['is_primary' => true]);

            $this->outbox->recordEventFor(
                $domain->store_id,
                eventType: 'domain.primary_changed',
                payload: ['domain_id' => $domain->id, 'store_id' => $domain->store_id],
                idempotencyKey: "domain:{$domain->id}:primary_changed:".Str::ulid(),
            );

            return $domain->fresh();
        });
    }

    /** Module 19 §38 "Domain Removal" — preserves the historical row (status flip), never a hard delete, unless Module 19 explicitly required deletion (it does not). */
    public function remove(Domain $domain): Domain
    {
        $this->stateMachine->assertCanTransition($domain->status, DomainStatus::Removed);

        return DB::transaction(function () use ($domain) {
            $domain->update(['status' => DomainStatus::Removed, 'is_primary' => false]);

            $this->outbox->recordEventFor(
                $domain->store_id,
                eventType: 'domain.removed',
                payload: ['domain_id' => $domain->id],
                idempotencyKey: "domain:{$domain->id}:removed",
            );

            return $domain->fresh();
        });
    }

    private function transitionTo(Domain $domain, DomainStatus $to): void
    {
        $this->stateMachine->assertCanTransition($domain->status, $to);
        $domain->update(['status' => $to]);
    }
}
