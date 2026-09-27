<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Http\Controllers;

use App\Domain\DataProtection\Exceptions\BackupNotRestoreEligibleException;
use App\Domain\DataProtection\Http\Resources\BackupResource;
use App\Domain\DataProtection\Http\Resources\BackupRestoreJobResource;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Policies\BackupPolicy;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\RestoreService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff-facing, store-scoped (Module 23 Phase 28 "Store Admin"). Every
 * query is EXPLICITLY filtered by store_id — Backup deliberately does
 * NOT use BelongsToTenant (see model docblock), so there is no
 * automatic scope to rely on here.
 */
final class BackupController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(app(BackupPolicy::class)->view($request->user()), 403);

        $storeId = app(TenantContext::class)->storeId();
        $backups = Backup::query()->where('store_id', $storeId)->orderByDesc('created_at')->paginate(25);

        return response()->json(['data' => BackupResource::collection($backups)]);
    }

    public function store(Request $request, BackupService $backups): JsonResponse
    {
        abort_unless(app(BackupPolicy::class)->manage($request->user()), 403);

        $store = Store::query()->findOrFail(app(TenantContext::class)->storeId());
        $backup = $backups->requestBackup(BackupScope::Store, $store, BackupInitiator::Manual, $request->user()->id);

        return (new BackupResource($backup))->response()->setStatusCode(201);
    }

    public function requestRestore(Request $request, Backup $backup, RestoreService $restores): JsonResponse
    {
        abort_unless(app(BackupPolicy::class)->requestRestore($request->user()), 403);

        // Explicit tenant check — Backup has no automatic scope (see
        // model docblock); this is the ONE place that guards it for
        // this controller's own store-scoped route.
        abort_unless($backup->store_id === app(TenantContext::class)->storeId(), 404);

        $restoreJob = $restores->requestRestore($backup, $backup->store_id, $request->user()->id);

        return (new BackupRestoreJobResource($restoreJob))->response()->setStatusCode(201);
    }
}
