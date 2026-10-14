<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Http\Controllers;

use App\Domain\Catalog\Services\ProductExport;
use App\Domain\Customers\Services\CustomerExport;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\SystemRoles;
use App\Domain\Orders\Services\OrderExport;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use App\Domain\Tenancy\Services\StoreClosureReport;
use App\Domain\Tenancy\Services\StoreLifecycleService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase B51 (Module 03 §35, §40; SRS STORE-009) — Settings → Close store.
 *
 * Every member sees where the store stands (so the admin can explain why it
 * is read-only). Only the store's Owner sees what is still open, downloads
 * the store's data, closes and reopens — closing is the Owner's decision,
 * not a permission another role can be given. Closing and reopening ask
 * for step-up and the store's name typed in full.
 */
final class StoreClosureController
{
    /** What the Owner can take with them, while the store is not yet archived. */
    public const DATASETS = ['orders', 'order-lines', 'customers', 'products'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly StoreLifecycleService $lifecycle,
        private readonly StoreClosureReport $report,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $store = $this->store();
        $owner = $this->isOwner($request->user(), $store);
        $report = $this->report->forStore($store);
        $public = array_intersect_key($report, array_flip(['status', 'stage_label', 'closed_at', 'suspended_at', 'retention_ends_at', 'retention_days', 'can_reopen']));

        return response()->json(['data' => $owner
            ? [...$report, 'is_owner' => true, 'store_name' => $store->name, 'datasets' => $this->canExport($store) ? self::DATASETS : []]
            : [...$public, 'is_owner' => false, 'can_reopen' => false, 'store_name' => $store->name, 'datasets' => []]]);
    }

    public function close(Request $request): JsonResponse
    {
        $store = $this->store();
        abort_unless($this->isOwner($request->user(), $store), 403, 'Only the store owner can close the store.');
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', Rule::in(array_diff(StoreLifecycleService::CLOSURE_REASONS, ['platform_request']))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $this->assertName($store, $data['store_name']);
        $store = $this->lifecycle->close($store, $data['reason'] ?? null, $data['note'] ?? null, $request->user(), byPlatform: false);

        return response()->json(['data' => $this->report->forStore($store)]);
    }

    public function reopen(Request $request): JsonResponse
    {
        $store = $this->store();
        abort_unless($this->isOwner($request->user(), $store), 403, 'Only the store owner can reopen the store.');
        $this->assertName($store, (string) $request->validate(['store_name' => ['required', 'string', 'max:255']])['store_name']);
        $store = $this->lifecycle->reopen($store, $request->user(), byPlatform: false);

        return response()->json(['data' => $this->report->forStore($store)]);
    }

    public function export(Request $request, string $dataset, OrderExport $orders, CustomerExport $customers, ProductExport $products): StreamedResponse
    {
        $store = $this->store();
        abort_unless($this->isOwner($request->user(), $store), 403, 'Only the store owner can download the store\'s data here.');
        abort_unless(in_array($dataset, self::DATASETS, true), 404);
        abort_unless($this->canExport($store), 423, 'This store is archived; its data can no longer be downloaded here.');

        return match ($dataset) {
            'orders' => $orders->orders($store->id, $request->user()),
            'order-lines' => $orders->lines($store->id, $request->user()),
            'customers' => $customers->stream([], $request->user()), // not package-gated here: the Owner's own data on leaving
            default => $products->stream([], $request->user()),
        };
    }

    private function store(): Store
    {
        return Store::query()->findOrFail($this->context->storeId());
    }

    private function canExport(Store $store): bool
    {
        return in_array($store->status, [StoreStatus::PendingSetup, StoreStatus::Active, StoreStatus::Suspended, StoreStatus::Cancelled], true);
    }

    private function isOwner(User $user, Store $store): bool
    {
        return DB::table('store_user')->join('roles', 'roles.id', '=', 'store_user.role_id')
            ->where('store_user.store_id', $store->id)->where('store_user.user_id', $user->id)
            ->where('store_user.status', 'active')->where('roles.slug', SystemRoles::OWNER)->exists();
    }

    private function assertName(Store $store, string $typed): void
    {
        if (mb_strtolower(trim($typed)) !== mb_strtolower(trim($store->name))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['store_name' => 'Type the store name exactly as shown: '.$store->name]);
        }
    }
}
