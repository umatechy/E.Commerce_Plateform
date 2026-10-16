<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Services;

use App\Domain\Affiliates\Support\AffiliateRules;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Resellers\Models\Reseller;
use App\Domain\Resellers\Models\ResellerStore;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase B58 (Module 28 §17, §68) — which reseller brought a store.
 *
 * Sources: the reseller created it in the portal ("provisioned"), the
 * client signed up through the reseller's link ("link": `/register?partner=
 * CODE`, remembered in the server session until sign-up), or Umar Techy
 * assigned it ("manual"). A store has at most one open attribution; it
 * carries a copy of the reseller's tier terms at that moment.
 */
final class ResellerAttribution
{
    private const SESSION_KEY = 'partner_ref';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The sign-up page opened with `?partner=CODE`: remember an active
     * reseller for this browser session. Returns the reseller to name.
     */
    public function rememberFromSignup(Request $request): ?Reseller
    {
        $code = AffiliateRules::normalizeCode((string) $request->query('partner', ''));
        if ($code !== '' && $request->hasSession()) {
            $reseller = Reseller::query()->where('code', $code)->where('status', 'active')->first();
            if ($reseller !== null) {
                $request->session()->put(self::SESSION_KEY, $reseller->id);
            }
        }

        return $this->fromSession($request);
    }

    public function fromSession(Request $request): ?Reseller
    {
        $id = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return is_int($id) ? Reseller::query()->where('id', $id)->where('status', 'active')->first() : null;
    }

    /** At sign-up: the store the client just created belongs to the reseller who sent them. */
    public function attachFromSession(Request $request, Store $store, User $owner): ?ResellerStore
    {
        $reseller = $this->fromSession($request);
        if ($reseller === null) {
            return null;
        }
        $request->session()->forget(self::SESSION_KEY);

        return $this->attach($reseller, $store, 'link', $owner);
    }

    /** Record that a reseller brought a store, with the terms of their tier now (§17). */
    public function attach(Reseller $reseller, Store $store, string $source, ?User $by, ?string $note = null, ?string $provisionKey = null): ResellerStore
    {
        $this->refuseIf(! $reseller->isActive(), 'reseller_not_active', 'Only an active reseller can be given a store.');
        $tier = $reseller->tier ?? throw new ResellerRefused('no_tier', 'The reseller has no tier.');
        $this->refuseIf($this->openFor($store->id) !== null, 'store_attributed', 'This store already belongs to a reseller.');

        $attribution = ResellerStore::query()->create([
            'reseller_id' => $reseller->id, 'store_id' => $store->id, 'source' => $source, 'provision_key' => $provisionKey,
            'terms' => $tier->terms(), 'attributed_by' => $by?->id, 'note' => $note,
        ]);
        $this->audit->record('resellers.store.attributed', ['source' => $source, 'store_id' => $store->id, 'terms' => $tier->terms()], $attribution, actor: $by, platform: true);

        return $attribution;
    }

    /** §68: Umar Techy gives a store to another reseller, with a reason. Earlier commissions stay. */
    public function reassign(User $by, Store $store, Reseller $reseller, string $reason): ResellerStore
    {
        return DB::transaction(function () use ($by, $store, $reseller, $reason) {
            $open = ResellerStore::query()->where('store_id', $store->id)->whereNull('ended_at')->lockForUpdate()->first();
            if ($open !== null) {
                $this->refuseIf($open->reseller_id === $reseller->id, 'same_reseller', 'The store already belongs to this reseller.');
                $this->end($by, $open, $reason);
            }

            return $this->attach($reseller, $store, 'manual', $by, $reason);
        });
    }

    /** Ends an attribution: no commissions on invoices issued from now on. */
    public function end(User $by, ResellerStore $attribution, string $reason): void
    {
        $this->refuseIf($attribution->ended_at !== null, 'already_ended', 'This store is no longer with the reseller.');
        $attribution->update(['ended_at' => now(), 'ended_reason' => $reason]);
        $this->audit->record('resellers.store.unattributed', ['reason' => $reason, 'store_id' => $attribution->store_id], $attribution, actor: $by, platform: true);
    }

    public function openFor(int $storeId): ?ResellerStore
    {
        return ResellerStore::query()->where('store_id', $storeId)->whereNull('ended_at')->first();
    }

    private function refuseIf(bool $condition, string $code, string $message): void
    {
        if ($condition) {
            throw new ResellerRefused($code, $message);
        }
    }
}
