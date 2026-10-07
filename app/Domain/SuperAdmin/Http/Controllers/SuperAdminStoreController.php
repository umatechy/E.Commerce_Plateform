<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Domains\Models\Domain;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Tenancy\Http\Resources\StoreResource;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Super Admin cross-tenant entry point (ADR-001 Layer 7).
 * `impersonate()`/`show()` are reached only via the
 * 'super_admin.impersonate' group (per-store); `index()` is
 * platform-global (Phase B16 fix — see
 * docs/development/b16-inspection-findings.md) and sits under the
 * separate 'super_admin.platform' group instead.
 */
final class SuperAdminStoreController
{
    /** Module 30 §9 "Store/Tenant Management" — search/list, platform-global (no target store). */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'business_category' => ['nullable', \Illuminate\Validation\Rule::in(\App\Domain\Tenancy\Support\BusinessCategories::keys())],
            'created_via' => ['nullable', \Illuminate\Validation\Rule::in(['self_service', 'platform'])],
        ]);
        $query = Store::query()->orderByDesc('id');

        if ($search = $filters['search'] ?? null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('slug', 'like', $like));
        }
        $query->when($filters['business_category'] ?? null, fn ($q, $c) => $q->where('business_category', $c))
            ->when($filters['created_via'] ?? null, fn ($q, $v) => $q->where('created_via', $v));

        // Phase B44 (Module 03 §38): each store with its stage, package, owner and how it was created.
        $page = $query->paginate(25);
        $lifecycle = \App\Domain\Tenancy\Services\StoreLifecycle::forStores(collect($page->items()));
        $page->through(fn (Store $store) => self::row($store, $lifecycle[$store->id] ?? null));

        return response()->json(['data' => $page]);
    }

    /**
     * @param ?array{stage: string, subscription: ?\App\Domain\Packages\Models\Subscription, has_owner: bool, owner_email: ?string} $life
     * @return array<string, mixed>
     */
    private static function row(Store $store, ?array $life): array
    {
        return [
            'id' => $store->id,
            'public_id' => $store->public_id,
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status->value,
            'business_category' => $store->business_category,
            'created_via' => $store->created_via,
            'created_at' => $store->created_at?->toIso8601String(),
            'activated_at' => $store->activated_at?->toIso8601String(),
            'stage' => $life['stage'] ?? 'onboarding',
            'package_code' => $life !== null ? $life['subscription']?->package?->code : null,
            'owner_email' => $life['owner_email'] ?? null,
            'has_owner' => $life !== null && $life['has_owner'],
        ];
    }

    /** Phase B44: what a store can sell — the fixed list (BusinessCategories), for forms and filters. */
    public function businessCategories(): JsonResponse
    {
        return response()->json(['data' => collect(\App\Domain\Tenancy\Support\BusinessCategories::ALL)
            ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values()]);
    }

    /**
     * Phase B44 — owner decision 13, Module 03 §5A, §38 "Create Store": Umar
     * Techy staff create a store for a customer — what it sells, the package
     * matching the customer's budget, the trial length (0 = the first invoice
     * is due at once) — and the customer gets an owner invitation by email.
     * Requires step-up; idempotent by Idempotency-Key for 24 hours.
     */
    public function store(Request $request, \App\Domain\Tenancy\Services\StoreProvisioningService $provisioning): JsonResponse
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:255'],
            'business_category' => ['required', \Illuminate\Validation\Rule::in(\App\Domain\Tenancy\Support\BusinessCategories::keys())],
            'package_code' => ['required', 'string', 'max:64'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'owner_email' => ['required', 'email', 'max:191'],
            'starter_template' => ['sometimes', 'boolean'], // Phase B45
            'starter_template_key' => ['nullable', 'string', 'max:40'], // any active template, e.g. one saved from another store
        ]);
        $data['idempotency_key'] = (string) $request->header('Idempotency-Key', '');

        try {
            $store = $provisioning->forCustomer($request->user(), $data);
        } catch (\App\Domain\Identity\Services\TeamActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode, 'errors' => [$e->field => [$e->getMessage()]]], 422);
        }

        return response()->json(['data' => ['id' => $store->id, 'public_id' => $store->public_id, 'name' => $store->name, 'slug' => $store->slug]], 201);
    }

    /** Module 30 §9/§18/§25 — a single store's operational snapshot: subscription, domain, low-stock count. Reuses each domain's own authoritative data, never a duplicate calculation. */
    public function show(Store $store): JsonResponse
    {
        $subscription = Subscription::query()->withoutTenantScope()->where('store_id', $store->id)->latest()->first();
        $primaryDomain = Domain::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_primary', true)->first();
        $lowStockCount = Inventory::query()->withoutTenantScope()->where('store_id', $store->id)
            ->whereNotNull('reorder_point')->whereRaw('(on_hand - reserved) <= reorder_point')->count();

        // Phase B44: stage, owner (or the open owner invitation) and setup progress (§24, §38 "assist with setup").
        $team = app(\App\Domain\Identity\Services\StoreTeamService::class);
        $hasOwner = $team->hasOwner();
        $invitation = $hasOwner ? null : $team->openOwnerInvitation();
        $setup = app(\App\Domain\Storefront\Services\StorefrontSetupService::class)->status($store);

        return response()->json(['data' => [
            'store' => new StoreResource($store),
            'subscription_status' => $subscription?->status->value,
            'package_code' => $subscription?->package?->code,
            'primary_domain' => $primaryDomain?->normalized_hostname,
            'low_stock_products' => $lowStockCount,
            'business_category' => $store->business_category,
            'created_via' => $store->created_via,
            'activated_at' => $store->activated_at?->toIso8601String(),
            'stage' => \App\Domain\Tenancy\Services\StoreLifecycle::stage($store, $subscription, $hasOwner),
            'owner_invitation' => $invitation === null ? null : ['email' => $invitation->email, 'expires_at' => $invitation->expires_at->toIso8601String()],
            'setup' => ['progress' => $setup['progress'], 'blocking' => $setup['blocking'], 'launched' => $setup['launched']],
            // Phase B45: starter templates applied to the store, newest first.
            'starter_templates' => \App\Domain\Catalog\Models\StarterTemplateApplication::query()->withoutTenantScope()
                ->where('store_id', $store->id)->latest('id')->limit(5)->get()
                ->map(fn (\App\Domain\Catalog\Models\StarterTemplateApplication $a) => ['key' => $a->template_key, 'name' => $a->summary['template_name'] ?? null, 'version' => $a->template_version, 'applied_at' => $a->created_at->toIso8601String()])
                ->values(),
        ]]);
    }

    /** Phase B44: a new owner invitation for a store that has no owner yet (the old link stops working). */
    public function inviteOwner(Request $request, Store $store, \App\Domain\Identity\Services\StoreTeamService $team): JsonResponse
    {
        $data = $request->validate(['owner_email' => ['required', 'email', 'max:191']]);

        try {
            $invitation = $team->inviteOwner($store, $data['owner_email'], $request->user());
        } catch (\App\Domain\Identity\Services\TeamActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }

        return response()->json(['data' => ['email' => $invitation->email, 'expires_at' => $invitation->expires_at->toIso8601String()]], 201);
    }

    /**
     * Module 30 §27 "Privileged Support / Impersonation" — Phase B16
     * hardening: a `reason` is now REQUIRED (was previously absent —
     * see inspection findings "Second Finding"), included in the audit
     * trail alongside the existing generic middleware-level log entry.
     */
    public function impersonate(Request $request, Store $store): StoreResource
    {
        $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        // TenantContext is already resolved to $store (impersonation
        // mode) by EnsureSuperAdminImpersonation — this line exists only
        // to make that reliance explicit and reviewable, not to redo it.
        app(\App\Domain\Compliance\Services\AuditLogger::class)->record('super_admin.store.impersonated', [
            'acting_super_admin_id' => $request->user()->id, 'target_store_id' => $store->id, 'reason' => $request->string('reason')->toString(),
        ], $store);

        return new StoreResource($store);
    }
}
