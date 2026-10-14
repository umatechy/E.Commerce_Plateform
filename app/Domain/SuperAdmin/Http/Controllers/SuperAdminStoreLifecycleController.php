<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\DataProtection\Models\Backup;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreDeletion;
use App\Domain\Tenancy\Models\StoreLifecycleEvent;
use App\Domain\Tenancy\Services\StoreClosureReport;
use App\Domain\Tenancy\Services\StoreDeletionRunner;
use App\Domain\Tenancy\Services\StoreLifecycleService;
use App\Domain\Tenancy\Services\StorePurger;
use App\Domain\Tenancy\Support\StoreDataMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase B51 — Module 30 §10 "Store Lifecycle Control", §140 "Tenant
 * Deletion Control"; Module 03 §33–38. Umar Techy's side of a store's life:
 * suspend and reactivate (with a reason), close on the owner's behalf,
 * archive after the retention period, restore from the archive, legal
 * hold, and permanent deletion — planned first (rows per table, what is
 * kept), requested with a reason and the store's address typed in full,
 * run after a cooling period and a verified backup, cancellable until it
 * starts. Platform staff only; every change asks for step-up.
 */
final class SuperAdminStoreLifecycleController
{
    public function __construct(
        private readonly StoreLifecycleService $lifecycle,
        private readonly StoreClosureReport $report,
        private readonly StorePurger $purger,
    ) {}

    public function show(Store $store): JsonResponse
    {
        $deletion = StoreDeletion::query()->where('store_id', $store->id)->orderByDesc('id')->first();

        return response()->json(['data' => [
            ...$this->report->forStore($store),
            'store' => ['id' => $store->public_id, 'name' => $store->name, 'slug' => $store->slug],
            'suspension_note' => $store->suspension_note,
            'archived_at' => $store->archived_at?->toIso8601String(),
            'legal_hold' => $store->legal_hold,
            'legal_hold_reason' => $store->legal_hold_reason,
            'allowed' => array_keys(array_filter(StoreLifecycleService::TRANSITIONS, fn (array $from, string $action) => ! str_contains($action, 'deletion') && in_array($store->status->value, $from, true), ARRAY_FILTER_USE_BOTH)),
            'suspension_reasons' => StoreLifecycleService::SUSPENSION_REASONS,
            'deletion_blockers' => array_values($this->lifecycle->deletionBlockers($store)),
            'deletion' => $deletion === null ? null : $this->deletion($deletion),
            'history' => StoreLifecycleEvent::query()->with('actor:id,name,email')->where('store_id', $store->id)->orderByDesc('id')->limit(50)->get()
                ->map(fn (StoreLifecycleEvent $e) => ['action' => $e->action, 'from' => $e->from_status, 'to' => $e->to_status, 'reason' => $e->reason, 'note' => $e->note,
                    'by' => $e->actor_type, 'actor' => $e->actor->name ?? $e->actor->email ?? null, 'at' => $e->created_at->toIso8601String()])->all(),
        ]]);
    }

    public function suspend(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', Rule::in(StoreLifecycleService::SUSPENSION_REASONS)], 'note' => ['required', 'string', 'min:5', 'max:500']]);
        $this->lifecycle->suspend($store, $data['reason'], $data['note'], $request->user());

        return $this->show($store->fresh() ?? $store);
    }

    public function reactivate(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500'], 'also_subscription' => ['sometimes', 'boolean']]);
        $this->lifecycle->reactivate($store, $data['note'], $request->user(), (bool) ($data['also_subscription'] ?? false));

        return $this->show($store->fresh() ?? $store);
    }

    public function close(Request $request, Store $store): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']])['note'];
        $this->lifecycle->close($store, 'platform_request', $note, $request->user(), byPlatform: true);

        return $this->show($store->fresh() ?? $store);
    }

    public function reopen(Request $request, Store $store): JsonResponse
    {
        $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);
        $this->lifecycle->reopen($store, $request->user(), byPlatform: true);

        return $this->show($store->fresh() ?? $store);
    }

    public function archive(Request $request, Store $store): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']])['note'];
        $this->lifecycle->archive($store, $request->user(), $note);

        return $this->show($store->fresh() ?? $store);
    }

    public function restore(Request $request, Store $store): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']])['note'];
        $this->lifecycle->restore($store, $note, $request->user());

        return $this->show($store->fresh() ?? $store);
    }

    public function legalHold(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['hold' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        $this->lifecycle->setLegalHold($store, $data['hold'], $data['reason'], $request->user());

        return $this->show($store->fresh() ?? $store);
    }

    /** What a deletion would remove and keep — counted now, nothing changed. */
    public function plan(Store $store): JsonResponse
    {
        $plan = $this->purger->plan($store);

        return response()->json(['data' => [
            'purge' => $plan['purge'], 'retain' => $plan['retain'],
            'purge_total' => array_sum($plan['purge']), 'retained_tables' => StoreDataMap::RETAIN,
            'blockers' => array_values($this->lifecycle->deletionBlockers($store)),
        ]]);
    }

    public function requestDeletion(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500'], 'confirm_slug' => ['required', 'string', 'max:255']]);
        if (trim($data['confirm_slug']) !== $store->slug) {
            throw ValidationException::withMessages(['confirm_slug' => 'Type the store address exactly: '.$store->slug]);
        }
        $this->lifecycle->requestDeletion($store, $data['reason'], $this->purger->plan($store)['purge'], $request->user());

        return $this->show($store->fresh() ?? $store);
    }

    public function cancelDeletion(Request $request, Store $store): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']])['note'];
        $deletion = StoreDeletion::query()->where('store_id', $store->id)->whereIn('status', StoreDeletion::OPEN)->orderByDesc('id')->firstOrFail();
        $this->lifecycle->cancelDeletion($deletion, $note, $request->user());

        return $this->show($store->fresh() ?? $store);
    }

    public function retryDeletion(Store $store, StoreDeletionRunner $runner): JsonResponse
    {
        $deletion = StoreDeletion::query()->where('store_id', $store->id)->where('status', StoreDeletion::FAILED)->orderByDesc('id')->firstOrFail();
        $runner->retry($deletion);

        return $this->show($store->fresh() ?? $store);
    }

    /** Platform list: every deletion, including stores already deleted (their records stay). */
    public function deletions(): JsonResponse
    {
        $rows = StoreDeletion::query()->orderByDesc('id')->limit(100)->get();
        $stores = Store::query()->withTrashed()->whereIn('id', $rows->pluck('store_id'))->get()->keyBy('id');

        return response()->json(['data' => $rows->map(fn (StoreDeletion $d) => [
            ...$this->deletion($d),
            'store' => ['id' => $stores->get($d->store_id)?->public_id, 'name' => $stores->get($d->store_id)?->name, 'slug' => $stores->get($d->store_id)?->slug],
        ])->all()]);
    }

    /** @return array<string, mixed> */
    private function deletion(StoreDeletion $d): array
    {
        $backup = $d->backup_id === null ? null : Backup::query()->withoutGlobalScopes()->find($d->backup_id);

        return [
            'id' => $d->public_id, 'status' => $d->status, 'reason' => $d->reason, 'not_before' => $d->not_before->toIso8601String(),
            'planned_rows' => array_sum((array) $d->plan), 'removed_rows' => array_sum((array) ($d->removed['rows'] ?? [])), 'removed_files' => (int) ($d->removed['files'] ?? 0),
            'remaining_rows' => array_sum((array) $d->remaining), 'backup' => $backup === null ? null : ['id' => $backup->public_id, 'status' => $backup->status->value],
            'failure_message' => $d->failure_message, 'started_at' => $d->started_at?->toIso8601String(), 'completed_at' => $d->completed_at?->toIso8601String(),
            'cancelled_at' => $d->cancelled_at?->toIso8601String(),
        ];
    }
}
