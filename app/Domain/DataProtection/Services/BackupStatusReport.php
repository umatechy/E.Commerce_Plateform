<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services;

use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Models\RestoreMode;
use App\Domain\DataProtection\Models\RestoreStatus;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Support\Carbon;

/**
 * The figures an operator needs about platform backups (Module 23
 * "Backup Monitoring"): read-only, computed from the backup and restore
 * records. Used by the Super Admin summary and by backups:monitor, so
 * the screen and the alert can never disagree.
 *
 * RPO and RTO are TARGETS (owner decision 2026-09-30 §5). This reports
 * measurements against them — the age of the newest verified backup,
 * the duration of the last rehearsal — and never says they are "met".
 */
final class BackupStatusReport
{
    /** @return array<string, mixed> */
    public function platform(): array
    {
        $rpoHours = (int) config('backup.rpo_hours', 24);
        $platform = fn () => Backup::query()->where('scope', BackupScope::Platform->value);

        $lastVerified = $platform()->where('status', BackupStatus::Verified->value)->orderByDesc('verified_at')->first();
        $lastScheduled = $platform()->where('initiated_by', BackupInitiator::Scheduled->value)->orderByDesc('id')->first();
        $lastFailed = $platform()->where('status', BackupStatus::Failed->value)->orderByDesc('id')->first();
        $rehearsals = fn () => BackupRestoreJob::query()->where('mode', RestoreMode::Rehearsal->value);
        $lastRehearsal = $rehearsals()->whereIn('status', [RestoreStatus::Completed->value, RestoreStatus::Failed->value])->orderByDesc('id')->first();
        $lastGoodRehearsal = $rehearsals()->where('status', RestoreStatus::Completed->value)->orderByDesc('id')->first();

        $hoursSince = $lastVerified?->verified_at !== null ? round($lastVerified->verified_at->diffInMinutes(now(), true) / 60, 1) : null;

        return [
            'rpo_target_hours' => $rpoHours,
            'rto_target_hours' => (int) config('backup.rto_hours', 4),
            'last_verified_backup_id' => $lastVerified?->public_id,
            'last_verified_at' => $lastVerified?->verified_at?->toIso8601String(),
            'last_integrity_check_at' => $lastVerified?->last_checked_at?->toIso8601String(),
            'hours_since_last_verified' => $hoursSince,
            'overdue' => $this->overdue($lastVerified?->verified_at, $rpoHours),
            'last_backup_size_bytes' => $lastVerified?->size_bytes,
            'last_backup_duration_ms' => $lastVerified?->manifest['duration_ms'] ?? null,
            'last_backup_encrypted' => $lastVerified?->is_encrypted,
            'last_scheduled_backup_status' => $lastScheduled?->status->value,
            'consecutive_scheduled_failures' => $this->consecutiveScheduledFailures(),
            'failed_backups_last_30_days' => $platform()->where('status', BackupStatus::Failed->value)->where('created_at', '>=', now()->subDays(30))->count(),
            'last_failure_reason' => $lastFailed?->failure_reason,
            'last_rehearsal_at' => $lastRehearsal?->completed_at?->toIso8601String(),
            'last_rehearsal_status' => $lastRehearsal?->status->value,
            'last_successful_rehearsal_at' => $lastGoodRehearsal?->completed_at?->toIso8601String(),
            'last_successful_rehearsal_duration_ms' => $lastGoodRehearsal?->duration_ms,
            'verified_backup_count' => $platform()->where('status', BackupStatus::Verified->value)->count(),
            'next_scheduled_backup_at' => $this->nextRun((string) config('backup.schedule.backup_at', '02:00'))->toIso8601String(),
        ];
    }

    /**
     * No verified platform backup inside the RPO window. A platform that
     * has not existed for that long yet is not overdue: there has been no
     * scheduled run to miss.
     */
    private function overdue(?\Carbon\CarbonInterface $lastVerifiedAt, int $rpoHours): bool
    {
        if ($lastVerifiedAt !== null) {
            return $lastVerifiedAt->lt(now()->subHours($rpoHours));
        }

        $firstStore = Store::query()->withTrashed()->min('created_at');

        return $firstStore !== null && Carbon::parse($firstStore)->lt(now()->subHours($rpoHours));
    }

    private function consecutiveScheduledFailures(): int
    {
        $count = 0;

        foreach (Backup::query()->where('initiated_by', BackupInitiator::Scheduled->value)->orderByDesc('id')->limit(10)->pluck('status') as $status) {
            if ($status !== BackupStatus::Failed) {
                break;
            }

            $count++;
        }

        return $count;
    }

    private function nextRun(string $time): Carbon
    {
        $next = Carbon::parse($time, 'UTC');

        return $next->isPast() ? $next->addDay() : $next;
    }
}
