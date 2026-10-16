<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Http\Controllers;

use App\Domain\Affiliates\Support\PayoutAccount;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Resellers\Models\Reseller;
use App\Domain\Resellers\Models\ResellerCommission;
use App\Domain\Resellers\Models\ResellerPayout;
use App\Domain\Resellers\Models\ResellerStore;
use App\Domain\Resellers\Services\ResellerDirectory;
use App\Domain\Resellers\Services\ResellerLedger;
use App\Domain\Resellers\Services\ResellerProvisioning;
use App\Domain\Resellers\Services\ResellerRefused;
use App\Domain\Tenancy\Support\BusinessCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase B58 (Module 28 §44 "reseller dashboard", §30, §96) — the partner
 * portal API, for the reseller signed in (EnsureActiveReseller put it on the
 * request). A reseller sees its terms, its sign-up link, the stores it
 * brought (name, state, package, whether the owner joined — never the
 * store's customers, orders or settings, §30), its commissions and payouts,
 * and creates stores for clients when its tier allows.
 */
final class PartnerController
{
    public function __construct(
        private readonly ResellerLedger $ledger,
        private readonly ResellerProvisioning $provisioning,
        private readonly ResellerDirectory $directory,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);
        $tier = $reseller->tier;
        $attributions = ResellerStore::query()->where('reseller_id', $reseller->id)->whereNull('ended_at')->pluck('store_id');
        $subscriptions = Subscription::query()->withoutTenantScope()->whereIn('store_id', $attributions)->get(['store_id', 'status']);

        return response()->json(['data' => [
            'business_name' => $reseller->business_name,
            'contact_name' => $reseller->contact_name,
            'status' => $reseller->status,
            'code' => $reseller->code,
            'signup_link' => rtrim((string) config('app.url'), '/').'/register?partner='.$reseller->code,
            'tier' => $tier === null ? null : [
                'name' => $tier->name,
                'client_discount_percent' => $tier->client_discount_bp / 100,
                'initial_commission_percent' => $tier->initial_commission_bp / 100,
                'recurring_commission_percent' => $tier->recurring_commission_bp / 100,
                'recurring_months' => $tier->recurring_months,
                'hold_days' => $tier->hold_days,
                'can_provision' => $tier->can_provision,
                'stores_left' => $this->provisioning->remaining($reseller),
                'payout_threshold_minor' => $tier->payout_threshold_minor,
            ],
            'stats' => [
                'stores' => $attributions->count(),
                'paying' => $subscriptions->filter(fn (Subscription $s) => in_array($s->status->value, ['active', 'past_due', 'grace_period'], true))->count(),
                'trialing' => $subscriptions->filter(fn (Subscription $s) => $s->status->value === 'trialing')->count(),
            ],
            'balances' => $this->ledger->balances($reseller->id)[$reseller->id] ?? [],
            'payout_details' => PayoutAccount::masked($reseller->payout_method, $reseller->payout_details),
            'business_categories' => collect(BusinessCategories::ALL)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
        ]]);
    }

    /** The stores this reseller brought: what a partner needs to follow its clients, nothing of their business. */
    public function stores(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);
        $rows = ResellerStore::query()->with('store')->where('reseller_id', $reseller->id)->orderByDesc('id')->limit(500)->get();
        $storeIds = $rows->pluck('store_id');
        $subscriptions = Subscription::query()->withoutTenantScope()->with('package:id,name')->whereIn('store_id', $storeIds)->get()->keyBy('store_id');
        $owners = DB::table('store_user')->join('roles', 'roles.id', '=', 'store_user.role_id')
            ->whereIn('store_user.store_id', $storeIds)->where('roles.slug', 'owner')->where('store_user.status', 'active')->pluck('store_user.store_id')->flip();
        $earned = ResellerCommission::query()->where('reseller_id', $reseller->id)->whereIn('status', ResellerCommission::LIVE)
            ->get(['reseller_store_id', 'amount_minor', 'reversed_minor', 'currency'])->groupBy('reseller_store_id');

        return response()->json(['data' => $rows->map(function (ResellerStore $a) use ($subscriptions, $owners, $earned) {
            $subscription = $subscriptions[$a->store_id] ?? null;
            $mine = $earned[$a->id] ?? collect();

            return [
                'id' => $a->public_id,
                'store' => $a->store->name,
                'store_status' => $a->store->status->value,
                'package' => $subscription?->package?->name,
                'subscription' => $subscription?->status->value,
                'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
                'owner_joined' => $owners->has($a->store_id),
                'source' => $a->source,
                'since' => $a->created_at->toIso8601String(),
                'ended_at' => $a->ended_at?->toIso8601String(),
                'client_discount_percent' => ((int) ($a->terms['client_discount_bp'] ?? 0)) / 100,
                'earned_minor' => (int) $mine->sum(fn (ResellerCommission $c) => $c->net()),
                'currency' => $mine->first()?->currency,
            ];
        })->all()]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);
        $rows = ResellerCommission::query()->with('attribution.store')->where('reseller_id', $reseller->id)->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (ResellerCommission $c) => [
            'id' => $c->public_id, 'date' => $c->created_at->toIso8601String(), 'store' => $c->attribution->store->name, 'kind' => $c->kind,
            'base_minor' => $c->base_minor, 'rate_percent' => $c->rate_bp / 100, 'amount_minor' => $c->net(), 'currency' => $c->currency,
            'status' => $c->status, 'hold_until' => $c->hold_until?->toIso8601String(),
        ])->all()]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);

        return response()->json(['data' => ResellerPayout::query()->where('reseller_id', $reseller->id)->where('status', '!=', 'cancelled')->orderByDesc('id')->limit(100)->get()
            ->map(fn (ResellerPayout $p) => ['date' => ($p->paid_at ?? $p->created_at)->toIso8601String(), 'amount_minor' => $p->amount_minor, 'currency' => $p->currency, 'status' => $p->status, 'reference' => $p->reference])->all()]);
    }

    public function packages(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->provisioning->packages($this->reseller($request))]);
    }

    /** §28, §96, §120: create a store for a client; the Idempotency-Key header makes a retry return the same store. */
    public function provision(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:255'],
            'business_category' => ['required', Rule::in(BusinessCategories::keys())],
            'package_code' => ['required', 'string', 'max:40'],
            'owner_email' => ['required', 'email', 'max:190'],
            'starter_template' => ['sometimes', 'boolean'],
        ]);
        $key = trim((string) $request->header('Idempotency-Key', ''));
        abort_if($key === '' || strlen($key) > 80, 422, 'Send an Idempotency-Key header.');
        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->provisioning->provision($reseller, $user, $data, $key);
        } catch (ResellerRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }

        return response()->json([
            'data' => ['id' => $result['attribution']->public_id, 'store' => $result['store']->name],
            'message' => 'Store created. We emailed the client an invitation to become its owner.',
        ], $result['created'] ? 201 : 200);
    }

    public function payoutDetails(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);
        $data = $request->validate(PayoutAccount::rules());
        /** @var User $user */
        $user = $request->user();
        $this->directory->setPayoutDetails($reseller, $data['method'], $data, $user);

        return response()->json(['data' => PayoutAccount::masked($reseller->payout_method, $reseller->refresh()->payout_details), 'message' => 'Payout details saved.']);
    }

    private function reseller(Request $request): Reseller
    {
        $reseller = $request->attributes->get('reseller');
        abort_unless($reseller instanceof Reseller, 403);

        return $reseller;
    }
}
