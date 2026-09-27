<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\DataProtection\Http\Resources\BackupResource;
use App\Domain\DataProtection\Http\Resources\BackupRestoreJobResource;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;
use App\Domain\DataProtection\Services\RestoreService;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 23 Phase 27 "Super Admin" — platform-wide oversight AND the
 * ONLY principal that can authorize+execute a restore (see
 * docs/development/b19-inspection-findings.md "Architectural Decision
 * — Restore Is Platform-Level Only"). Reuses B16's existing
 * super_admin.platform route group unchanged.
 */
final class SuperAdminBackupController
{
    public function index(): JsonResponse
    {
        $backups = Backup::query()->orderByDesc('created_at')->paginate(50);

        return response()->json(['data' => BackupResource::collection($backups)]);
    }

    public function storePlatformBackup(Request $request, BackupService $backups): JsonResponse
    {
        $backup = $backups->requestBackup(BackupScope::Platform, null, BackupInitiator::Manual, $request->user()->id);

        return (new BackupResource($backup))->response()->setStatusCode(201);
    }

    public function restoreJobs(): JsonResponse
    {
        $jobs = BackupRestoreJob::query()->with('backup')->orderByDesc('created_at')->paginate(50);

        return response()->json(['data' => BackupRestoreJobResource::collection($jobs)]);
    }

    public function authorizeRestore(
        Request $request,
        BackupRestoreJob $backupRestoreJob,
        RestoreService $restores,
        BackupService $backupService,
        DatabaseDumpStrategy $dumper,
        BackupStorageAdapter $storage,
    ): JsonResponse {
        $restores->authorizeAndExecute($backupRestoreJob, $request->user()->id, $backupService, $dumper, $storage);

        return response()->json(['data' => new BackupRestoreJobResource($backupRestoreJob->fresh())]);
    }
}
