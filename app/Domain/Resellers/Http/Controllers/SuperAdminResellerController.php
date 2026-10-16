<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Http\Controllers;

use App\Domain\Affiliates\Support\PayoutAccount;
use App\Domain\Identity\Models\User;
use App\Domain\Resellers\Models\Reseller;
use App\Domain\Resellers\Models\ResellerAdjustment;
use App\Domain\Resellers\Models\ResellerCommission;
use App\Domain\Resellers\Models\ResellerPayout;
use App\Domain\Resellers\Models\ResellerStore;
use App\Domain\Resellers\Models\ResellerTier;
use App\Domain\Resellers\Services\ResellerAttribution;
use App\Domain\Resellers\Services\ResellerDirectory;
use App\Domain\Resellers\Services\ResellerLedger;
use App\Domain\Resellers\Services\ResellerRefused;
use App\Domain\Settings\Services\Currencies;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase B58 (Module 28 §46 "Super Admin dashboard", §26, §68–72, §90) —
 * Super Admin → Resellers: tiers and their terms, resellers and
 * applications, which stores each brought, commissions and payouts.
 *
 * Capability `resellers` (PlatformRoles). Changing terms, assigning stores,
 * terminating, payout details, payouts and adjustments also need step-up
 * on their routes. Everything is audited on the platform chain.
 */
final class SuperAdminResellerController
{
    public function __construct(
        private readonly ResellerDirectory $directory,
        private readonly ResellerAttribution $attribution,
        private readonly ResellerLedger $ledger,
    ) {}

    public function overview(): JsonResponse
    {
        $balances = $this->ledger->balances();
        $totals = [];
        foreach ($balances as $byCurrency) {
            foreach ($byCurrency as $currency => $b) {
                $totals[$currency] ??= ['pending_minor' => 0, 'payable_minor' => 0, 'in_payout_minor' => 0, 'paid_minor' => 0];
                foreach ($b as $key => $amount) {
                    $totals[$currency][$key] += $amount;
                }
            }
        }

        return response()->json(['data' => [
            'tiers' => ResellerTier::query()->withCount('resellers')->orderBy('name')->get()->map(fn (ResellerTier $t) => $this->tier($t))->all(),
            'counts' => Reseller::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'stores' => ResellerStore::query()->whereNull('ended_at')->count(),
            'totals' => $totals,
            'currencies' => Currencies::supported(),
        ]]);
    }

    public function storeTier(Request $request): JsonResponse
    {
        $data = $request->validate($this->tierRules());
        $tier = ResellerTier::query()->create($this->tierData($data));
        app(\App\Domain\Compliance\Services\AuditLogger::class)->record('resellers.tier.created', $tier->terms(), $tier, actor: $request->user(), platform: true);

        return response()->json(['data' => $this->tier($tier), 'message' => 'Tier created.'], 201);
    }

    /** New terms apply to stores attributed from now on; stores already attributed keep theirs (§17). */
    public function updateTier(Request $request, ResellerTier $tier): JsonResponse
    {
        $data = $request->validate($this->tierRules($tier));
        $before = $tier->terms();
        $tier->update($this->tierData($data));
        app(\App\Domain\Compliance\Services\AuditLogger::class)->record('resellers.tier.updated', ['before' => $before, 'after' => $tier->terms(), 'is_active' => $tier->is_active], $tier, actor: $request->user(), platform: true);

        return response()->json(['data' => $this->tier($tier->loadCount('resellers')), 'message' => 'Tier saved. Stores attributed from now on get these terms; stores already attributed keep theirs.']);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(Reseller::STATUSES)], 'q' => ['nullable', 'string', 'max:100']]);
        $balances = $this->ledger->balances();
        $stores = ResellerStore::query()->whereNull('ended_at')->selectRaw('reseller_id, count(*) as n')->groupBy('reseller_id')->pluck('n', 'reseller_id');
        $rows = Reseller::query()->with('tier:id,name')
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('business_name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->orderByRaw("FIELD(status, 'pending', 'invited', 'active', 'paused', 'terminated', 'rejected')")->orderBy('business_name')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn (Reseller $r) => [
            'id' => $r->public_id, 'business_name' => $r->business_name, 'contact_name' => $r->contact_name, 'email' => $r->email, 'code' => $r->code,
            'status' => $r->status, 'source' => $r->source, 'tier' => $r->tier?->name, 'stores' => (int) ($stores[$r->id] ?? 0),
            'balances' => $balances[$r->id] ?? [], 'created_at' => $r->created_at?->toIso8601String(),
        ])->all()]);
    }

    public function show(Reseller $reseller): JsonResponse
    {
        $attributions = ResellerStore::query()->with('store')->where('reseller_id', $reseller->id)->orderByDesc('id')->limit(500)->get();

        return response()->json(['data' => [
            'id' => $reseller->public_id, 'business_name' => $reseller->business_name, 'contact_name' => $reseller->contact_name, 'email' => $reseller->email,
            'phone' => $reseller->phone, 'country' => $reseller->country, 'website' => $reseller->website, 'code' => $reseller->code,
            'status' => $reseller->status, 'status_reason' => $reseller->status_reason, 'source' => $reseller->source, 'application_note' => $reseller->application_note,
            'tier' => $reseller->tier === null ? null : ['id' => $reseller->tier->public_id, 'name' => $reseller->tier->name],
            'has_account' => $reseller->user_id !== null, 'invitation_expires_at' => $reseller->invitation_expires_at?->toIso8601String(),
            'joined_at' => $reseller->terms_accepted_at?->toIso8601String(), 'terminated_at' => $reseller->terminated_at?->toIso8601String(),
            'payout' => PayoutAccount::masked($reseller->payout_method, $reseller->payout_details),
            'balances' => $this->ledger->balances($reseller->id)[$reseller->id] ?? [],
            'stores' => $attributions->map(fn (ResellerStore $a) => [
                'id' => $a->public_id, 'store' => ['id' => $a->store_id, 'name' => $a->store->name, 'status' => $a->store->status->value],
                'source' => $a->source, 'since' => $a->created_at->toIso8601String(), 'ended_at' => $a->ended_at?->toIso8601String(), 'ended_reason' => $a->ended_reason,
                'terms' => $a->terms, 'note' => $a->note,
            ])->all(),
            'adjustments' => ResellerAdjustment::query()->where('reseller_id', $reseller->id)->orderByDesc('id')->limit(100)->get()
                ->map(fn (ResellerAdjustment $a) => ['amount_minor' => $a->amount_minor, 'currency' => $a->currency, 'reason' => $a->reason, 'automatic' => $a->created_by === null, 'settled' => $a->reseller_payout_id !== null, 'created_at' => $a->created_at->toIso8601String()])->all(),
        ]]);
    }

    /** Invite a business (§92). The invitation link is returned once; it is also emailed. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([...$this->profileRules(), 'email' => ['required', 'email', 'max:190'], 'code' => ['nullable', 'string', 'max:30'], 'tier' => ['required', 'string', 'size:26']]);
        $tier = ResellerTier::query()->where('public_id', $data['tier'])->firstOrFail();

        return $this->attempt(function () use ($request, $tier, $data) {
            [$reseller, $url] = $this->directory->invite($this->user($request), $tier, $data);

            return response()->json(['data' => ['id' => $reseller->public_id, 'code' => $reseller->code, 'invitation_url' => $url],
                'message' => 'Invited. We emailed them the invitation; you can also copy its link now.'], 201);
        });
    }

    public function update(Request $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validate([...$this->profileRules(), 'tier' => ['nullable', 'string', 'size:26']]);
        $tier = isset($data['tier']) ? ResellerTier::query()->where('public_id', $data['tier'])->firstOrFail() : null;

        return $this->attempt(function () use ($request, $reseller, $data, $tier) {
            $this->directory->update($this->user($request), $reseller, $data, $tier);

            return response()->json(['message' => 'Saved. A new tier applies to stores attributed from now on.']);
        });
    }

    public function approve(Request $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validate(['tier' => ['required', 'string', 'size:26']]);
        $tier = ResellerTier::query()->where('public_id', $data['tier'])->firstOrFail();

        return $this->attempt(fn () => response()->json([
            'data' => ['invitation_url' => $this->directory->approve($this->user($request), $reseller, $tier)],
            'message' => 'Approved. We emailed them an invitation to the partner portal.',
        ]));
    }

    public function reinvite(Request $request, Reseller $reseller): JsonResponse
    {
        return $this->attempt(fn () => response()->json([
            'data' => ['invitation_url' => $this->directory->reinvite($this->user($request), $reseller)],
            'message' => 'New invitation sent. The old link stopped working.',
        ]));
    }

    public function reject(Request $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        return $this->attempt(function () use ($request, $reseller, $data) {
            $this->directory->reject($this->user($request), $reseller, $data['reason'] ?? null);

            return response()->json(['message' => 'Declined.']);
        });
    }

    public function pause(Request $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        return $this->attempt(function () use ($request, $reseller, $data) {
            $this->directory->pause($this->user($request), $reseller, $data['reason'] ?? null);

            return response()->json(['message' => 'Paused. They cannot create stores and new sign-ups through their link do not count; commissions continue.']);
        });
    }

    public function resume(Request $request, Reseller $reseller): JsonResponse
    {
        return $this->attempt(function () use ($request, $reseller) {
            $this->directory->resume($this->user($request), $reseller);

            return response()->json(['message' => 'Active again.']);
        });
    }

    public function terminate(Request $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);

        return $this->attempt(function () use ($request, $reseller, $data) {
            $this->directory->terminate($this->user($request), $reseller, $data['reason']);

            return response()->json(['message' => 'Terminated. Their portal is closed and invoices from now on earn nothing; earned commissions can still be paid. Their clients keep their stores.']);
        });
    }

    public function payoutDetails(Request $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validate(PayoutAccount::rules());
        $this->directory->setPayoutDetails($reseller, $data['method'], $data, $this->user($request));

        return response()->json(['data' => PayoutAccount::masked($reseller->payout_method, $reseller->refresh()->payout_details), 'message' => 'Payout details saved.']);
    }

    /** §68: give a store to this reseller (ending another reseller's attribution), with a reason. */
    public function assignStore(Request $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validate(['store' => ['required', 'string', 'max:120'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        $store = Store::query()->withoutGlobalScopes()->where(ctype_digit($data['store']) ? 'id' : 'slug', $data['store'])->first();
        abort_if($store === null, 422, 'No store with that number or address.');

        return $this->attempt(function () use ($request, $reseller, $store, $data) {
            $this->attribution->reassign($this->user($request), $store, $reseller, $data['reason']);

            return response()->json(['message' => "{$store->name} now counts for {$reseller->business_name}, from its next invoice."], 201);
        });
    }

    public function endAttribution(Request $request, ResellerStore $attribution): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);

        return $this->attempt(function () use ($request, $attribution, $data) {
            $this->attribution->end($this->user($request), $attribution, $data['reason']);

            return response()->json(['message' => 'The store no longer counts for the reseller. Its partner price ends with its next invoice.']);
        });
    }

    public function commissions(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'paid', 'rejected', 'reversed'])], 'reseller' => ['nullable', 'string', 'size:26']]);
        $page = ResellerCommission::query()->with(['reseller:id,public_id,business_name', 'attribution.store', 'invoice:id,number'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['reseller'] ?? null, fn ($q, $id) => $q->whereIn('reseller_id', Reseller::query()->where('public_id', $id)->select('id')))
            ->orderByDesc('id')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (ResellerCommission $c) => [
                'id' => $c->public_id, 'status' => $c->status, 'kind' => $c->kind,
                'reseller' => ['id' => $c->reseller->public_id, 'name' => $c->reseller->business_name],
                'store' => $c->attribution->store->name, 'invoice' => $c->invoice->number,
                'base_minor' => $c->base_minor, 'rate_percent' => $c->rate_bp / 100, 'amount_minor' => $c->amount_minor, 'reversed_minor' => $c->reversed_minor,
                'net_minor' => $c->net(), 'currency' => $c->currency, 'hold_until' => $c->hold_until?->toIso8601String(),
                'note' => $c->decision_note, 'in_payout' => $c->reseller_payout_id !== null, 'created_at' => $c->created_at->toIso8601String(),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function rejectCommission(Request $request, ResellerCommission $commission): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->attempt(function () use ($request, $commission, $data) {
            $this->ledger->reject($this->user($request), $commission, $data['note']);

            return response()->json(['message' => 'Rejected: it will not be paid.']);
        });
    }

    public function payouts(): JsonResponse
    {
        return response()->json(['data' => ResellerPayout::query()->with('reseller:id,public_id,business_name')->orderByDesc('id')->limit(200)->get()
            ->map(fn (ResellerPayout $p) => [
                'id' => $p->public_id, 'reseller' => ['id' => $p->reseller->public_id, 'name' => $p->reseller->business_name], 'status' => $p->status,
                'amount_minor' => $p->amount_minor, 'currency' => $p->currency, 'method' => $p->method, 'reference' => $p->reference, 'note' => $p->note,
                'created_at' => $p->created_at->toIso8601String(), 'paid_at' => $p->paid_at?->toIso8601String(),
            ])->all()]);
    }

    public function createPayout(Request $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validate(['currency' => ['required', 'string', 'size:3'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($request, $reseller, $data) {
            $user = $this->user($request);
            $payout = $this->ledger->createPayout($user, $reseller, $data['currency'], $data['note'] ?? null);

            return response()->json(['data' => [
                'id' => $payout->public_id, 'amount_minor' => $payout->amount_minor, 'currency' => $payout->currency,
                // The full account: Umar Techy pays it itself (step-up on the route; audited).
                'pay_to' => $this->directory->revealPayout($user, $reseller, 'payout '.$payout->public_id),
            ], 'message' => 'Payout prepared. Pay it, then record the transaction reference.'], 201);
        });
    }

    public function payTo(Request $request, ResellerPayout $payout): JsonResponse
    {
        abort_if($payout->status !== 'pending', 404);

        return response()->json(['data' => $this->directory->revealPayout($this->user($request), $payout->reseller, 'payout '.$payout->public_id)]);
    }

    public function markPaid(Request $request, ResellerPayout $payout): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'min:3', 'max:120']]);

        return $this->attempt(function () use ($request, $payout, $data) {
            $this->ledger->markPaid($this->user($request), $payout, $data['reference']);

            return response()->json(['message' => 'Recorded as paid.']);
        });
    }

    public function cancelPayout(Request $request, ResellerPayout $payout): JsonResponse
    {
        return $this->attempt(function () use ($request, $payout) {
            $this->ledger->cancelPayout($this->user($request), $payout);

            return response()->json(['message' => 'Cancelled. Its commissions are payable again.']);
        });
    }

    public function adjust(Request $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'not_in:0', 'between:-100000000000,100000000000'],
            'currency' => ['required', 'string', 'size:3'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        return $this->attempt(function () use ($request, $reseller, $data) {
            $this->ledger->adjust($this->user($request), $reseller, (int) $data['amount_minor'], $data['currency'], $data['reason']);

            return response()->json(['message' => 'Adjustment recorded. It is settled with the next payout in that currency.']);
        });
    }

    /** @return array<string, mixed> */
    private function tier(ResellerTier $t): array
    {
        return [
            'id' => $t->public_id, 'name' => $t->name, 'description' => $t->description, 'is_active' => $t->is_active,
            'client_discount_percent' => $t->client_discount_bp / 100, 'initial_commission_percent' => $t->initial_commission_bp / 100,
            'recurring_commission_percent' => $t->recurring_commission_bp / 100, 'recurring_months' => $t->recurring_months,
            'hold_days' => $t->hold_days, 'can_provision' => $t->can_provision, 'max_stores' => $t->max_stores,
            'payout_threshold_minor' => $t->payout_threshold_minor, 'resellers' => (int) ($t->resellers_count ?? 0),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function tierRules(?ResellerTier $tier = null): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('reseller_tiers', 'name')->ignore($tier?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'client_discount_percent' => ['required', 'numeric', 'min:0', 'max:50'],
            'initial_commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'recurring_commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'recurring_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'hold_days' => ['required', 'integer', 'min:0', 'max:180'],
            'can_provision' => ['required', 'boolean'],
            'max_stores' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'payout_threshold_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Percentages as basis points (decimal-safe, §65).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function tierData(array $data): array
    {
        $bp = fn (mixed $percent) => (int) round(((float) $percent) * 100);

        return [
            'name' => trim((string) $data['name']), 'description' => $data['description'] ?? null,
            'client_discount_bp' => $bp($data['client_discount_percent']), 'initial_commission_bp' => $bp($data['initial_commission_percent']),
            'recurring_commission_bp' => $bp($data['recurring_commission_percent']), 'recurring_months' => $data['recurring_months'] ?? null,
            'hold_days' => (int) $data['hold_days'], 'can_provision' => (bool) $data['can_provision'], 'max_stores' => $data['max_stores'] ?? null,
            'payout_threshold_minor' => (int) $data['payout_threshold_minor'], 'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function profileRules(): array
    {
        return [
            'business_name' => ['required', 'string', 'min:2', 'max:160'],
            'contact_name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'size:2'],
            'website' => ['nullable', 'url', 'max:255'],
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function attempt(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (ResellerRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }
    }
}
