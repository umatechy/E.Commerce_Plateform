<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\DataProtection\Http\Resources\BackupResource;
use App\Domain\DataProtection\Http\Resources\BackupRestoreJobResource;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Exceptions\BackupNotRestoreEligibleException;
use App\Domain\DataProtection\Jobs\RunRestoreRehearsalJob;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\BackupStatusReport;
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
    public function index(Request $request): JsonResponse
    {
        $backups = Backup::query()->orderByDesc('created_at')->paginate(50);

        // The paginator itself goes under `data` (same shape as every other
        // Super Admin list: data.total, data.data, ...), with each item
        // transformed by its Resource. Wrapping a ResourceCollection in an
        // array instead silently dropped all pagination metadata.
        return response()->json(['data' => $backups->through(fn (Backup $backup) => (new BackupResource($backup))->resolve($request))]);
    }

    public function storePlatformBackup(Request $request, BackupService $backups): JsonResponse
    {
        $backup = $backups->requestBackup(BackupScope::Platform, null, BackupInitiator::Manual, $request->user()->id);

        return (new BackupResource($backup))->response()->setStatusCode(201);
    }

    public function restoreJobs(Request $request): JsonResponse
    {
        $jobs = BackupRestoreJob::query()->with('backup')->orderByDesc('created_at')->paginate(50);

        return response()->json(['data' => $jobs->through(fn (BackupRestoreJob $job) => (new BackupRestoreJobResource($job))->resolve($request))]);
    }

    /**
     * Module 23 Phase 17–19 (Phase B30): a production restore replaces
     * the live database. Behind MFA and step-up (route middleware); here
     * it also needs the backup's id typed back as confirmation and an
     * incident or change reference. A refusal changes nothing.
     */
    public function authorizeRestore(Request $request, BackupRestoreJob $backupRestoreJob, RestoreService $restores, DatabaseDumpStrategy $dumper): JsonResponse
    {
        $input = $request->validate([
            'confirmation' => ['required', 'string', 'max:64'],
            'reference' => ['required', 'string', 'min:5', 'max:120'],
        ]);

        try {
            $restores->authorizeAndExecute($backupRestoreJob, $request->user()->id, $input['confirmation'], $input['reference'], $dumper);
        } catch (BackupNotRestoreEligibleException $e) {
            return response()->json(['message' => 'The restore was not started: '.$e->getMessage().'.', 'code' => 'restore_refused'], 422);
        }

        return response()->json(['data' => new BackupRestoreJobResource($backupRestoreJob->fresh())]);
    }

    /** Last backup, last rehearsal, what failed, what is next (Module 23 "Backup Monitoring"). */
    public function summary(BackupStatusReport $report): JsonResponse
    {
        return response()->json(['data' => $report->platform()]);
    }

    /** Re-reads the stored artifact and compares it with what was recorded (Module 23 §15). */
    public function verify(Backup $backup, BackupService $backups, BackupStorageAdapter $storage): JsonResponse
    {
        if ($backup->status !== BackupStatus::Verified) {
            return response()->json(['message' => 'Only a verified backup can be re-checked.', 'code' => 'backup_not_verified'], 422);
        }

        $intact = $backups->recheck($backup, $storage, deep: true);

        return response()->json(['data' => ['intact' => $intact, 'backup' => (new BackupResource($backup->fresh()))->resolve()]]);
    }

    /**
     * Queues a restore rehearsal: the backup is restored into a
     * throw-away database, checked and dropped. Live data is not written.
     */
    public function rehearse(Request $request, Backup $backup): JsonResponse
    {
        if (! $backup->isRestoreEligible()) {
            return response()->json(['message' => 'Only a verified, unexpired backup can be rehearsed.', 'code' => 'backup_not_verified'], 422);
        }

        RunRestoreRehearsalJob::dispatch($backup->id, $request->user()->id);

        return response()->json(['data' => ['queued' => true]], 202);
    }
}
