<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreDeletion;
use App\Domain\Tenancy\Models\StoreStatus;
use Illuminate\Support\Facades\DB;

/**
 * Phase B51 (Module 30 §140: … BACKUP → CONFIRM → SOFT DELETE/ARCHIVE →
 * RETENTION → HARD DELETE IF ELIGIBLE → VERIFY) — carries an approved
 * deletion through, one step per call, so the scheduler can run it and a
 * stopped run continues safely:
 *
 * 1. Past its cooling period, the checks are made again (a legal hold or
 *    an open invoice added since stops it).
 * 2. A platform backup is taken ("before a store deletion"); nothing is
 *    removed until it is verified. A failed backup fails the deletion.
 * 3. The store turns "deleting"; StorePurger removes rows and files.
 * 4. Every purged table is counted again: only when all are zero is the
 *    store marked deleted (soft-deleted row kept for financial records).
 */
final class StoreDeletionRunner
{
    public function __construct(
        private readonly StoreLifecycleService $lifecycle,
        private readonly StorePurger $purger,
        private readonly BackupService $backups,
        private readonly AuditLogger $audit,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /** @return int deletions that moved a step */
    public function runDue(): int
    {
        $moved = 0;
        StoreDeletion::query()->whereIn('status', [StoreDeletion::SCHEDULED, StoreDeletion::BACKING_UP, StoreDeletion::PURGING])
            ->where('not_before', '<=', now())->orderBy('id')->get()
            ->each(function (StoreDeletion $deletion) use (&$moved) {
                $moved += $this->step($deletion) ? 1 : 0;
            });

        return $moved;
    }

    public function step(StoreDeletion $deletion): bool
    {
        $deletion = $deletion->fresh() ?? $deletion;
        $store = Store::query()->withTrashed()->findOrFail($deletion->store_id);

        return match ($deletion->status) {
            StoreDeletion::SCHEDULED => $this->startBackup($deletion, $store),
            StoreDeletion::BACKING_UP => $this->afterBackup($deletion, $store),
            StoreDeletion::PURGING => $this->purge($deletion, $store),
            default => false,
        };
    }

    private function startBackup(StoreDeletion $deletion, Store $store): bool
    {
        if ($deletion->not_before->isFuture()) {
            return false;
        }
        $blockers = $this->lifecycle->deletionBlockers($store);
        unset($blockers['deletion_open']); // this one
        if ($store->status === StoreStatus::Deleting) {
            unset($blockers['not_archived']); // a run that stopped half-way continues
        }
        if ($blockers !== []) {
            return $this->fail($deletion, $store, 'Stopped before anything was removed: '.implode(' ', $blockers));
        }
        $claimed = StoreDeletion::query()->whereKey($deletion->id)->where('status', StoreDeletion::SCHEDULED)
            ->update(['status' => StoreDeletion::BACKING_UP, 'started_at' => now(), 'updated_at' => now()]);
        if ($claimed === 0) {
            return false; // another run took it
        }
        $asked = now()->subSecond();
        try {
            $backup = $this->backups->requestBackup(BackupScope::Platform, null, BackupInitiator::PreDeletionSafety, $deletion->requested_by);
        } catch (\Throwable $e) {
            // A queue that runs the backup at once (sync) passes its failure on: the row exists and says failed.
            report($e);
            $backup = Backup::query()->withoutGlobalScopes()->where('initiated_by', BackupInitiator::PreDeletionSafety->value)->where('created_at', '>=', $asked)->orderByDesc('id')->first();
        }
        $deletion->forceFill(['backup_id' => $backup?->id])->save();

        return $this->afterBackup($deletion->fresh() ?? $deletion, $store);
    }

    private function afterBackup(StoreDeletion $deletion, Store $store): bool
    {
        $backup = Backup::query()->withoutGlobalScopes()->find($deletion->backup_id);
        if ($backup === null || in_array($backup->status, [BackupStatus::Failed, BackupStatus::Cancelled, BackupStatus::Expired, BackupStatus::Deleted], true)) {
            return $this->fail($deletion, $store, 'The backup before deletion did not complete, so nothing was removed. Retry when backups work again.');
        }
        if ($backup->status !== BackupStatus::Verified) {
            return false; // still running; the next run looks again
        }
        $claimed = StoreDeletion::query()->whereKey($deletion->id)->where('status', StoreDeletion::BACKING_UP)
            ->update(['status' => StoreDeletion::PURGING, 'updated_at' => now()]);
        if ($claimed === 0) {
            return false;
        }

        return $this->purge($deletion->fresh() ?? $deletion, $store);
    }

    private function purge(StoreDeletion $deletion, Store $store): bool
    {
        try {
            if ($store->status === StoreStatus::Archived) {
                $store = $this->lifecycle->markDeleting($store);
            }
            $result = $this->purger->purge($store);
            $left = $this->purger->verify($store);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail($deletion, $store, 'Stopped while removing data: '.mb_substr($e->getMessage(), 0, 300).' Retry continues where it stopped.');
        }
        $previous = (array) ($deletion->removed ?? []);
        $removed = ['rows' => $this->sum((array) ($previous['rows'] ?? []), $result['rows']), 'files' => (int) ($previous['files'] ?? 0) + $result['files']];
        if (array_sum($left) > 0) {
            $deletion->forceFill(['removed' => $removed, 'remaining' => array_filter($left)])->save();

            return $this->fail($deletion, $store, 'Rows were still left after removal; the store is not marked deleted. Retry continues.');
        }
        DB::transaction(function () use ($deletion, $store, $removed) {
            $deletion->forceFill(['status' => StoreDeletion::COMPLETED, 'removed' => $removed, 'remaining' => [], 'completed_at' => now(), 'failure_message' => null])->save();
            $this->lifecycle->markDeleted($store);
            $this->audit->record('store.deleted', ['deletion' => $deletion->public_id, 'rows' => array_sum($removed['rows']), 'files' => $removed['files'], 'backup_id' => $deletion->backup_id], $store, $store->id);
            // Platform-level: the store's own records are gone, so the event is not the store's.
            $this->outbox->recordEventFor(null, 'store.lifecycle_changed', ['store_id' => $store->id, 'action' => 'deleted', 'deletion_id' => $deletion->id], "store:{$store->id}:deletion:{$deletion->public_id}:completed");
        });

        return true;
    }

    /** Back to the start after a failure; a half-done purge simply continues. */
    public function retry(StoreDeletion $deletion): StoreDeletion
    {
        $updated = StoreDeletion::query()->whereKey($deletion->id)->where('status', StoreDeletion::FAILED)
            ->update(['status' => StoreDeletion::SCHEDULED, 'backup_id' => null, 'failure_message' => null, 'not_before' => now(), 'updated_at' => now()]);
        if ($updated === 0) {
            throw new \App\Domain\Tenancy\Exceptions\StoreLifecycleException('not_failed', 'Only a failed deletion can be retried.');
        }

        return $deletion->fresh() ?? $deletion;
    }

    private function fail(StoreDeletion $deletion, Store $store, string $message): bool
    {
        $deletion->forceFill(['status' => StoreDeletion::FAILED, 'failure_message' => mb_substr($message, 0, 500)])->save();
        $this->audit->record('store.deletion_failed', ['deletion' => $deletion->public_id, 'message' => $message], $store, $store->id);

        return true;
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     * @return array<string, int>
     */
    private function sum(array $a, array $b): array
    {
        foreach ($b as $table => $count) {
            $a[$table] = ($a[$table] ?? 0) + $count;
        }

        return $a;
    }
}
