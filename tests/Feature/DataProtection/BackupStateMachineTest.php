<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\DataProtection\Exceptions\InvalidBackupStateTransitionException;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\BackupStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B19 — Backup lifecycle: explicit, validated transitions only
 * (Module 23 §17, Phase 11, Non-Negotiable).
 * STATUS: NOT EXECUTED — ENVIRONMENT LIMITATION (no PHP/MySQL runtime
 * available in this Claude App sandbox).
 */
final class BackupStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_created_can_transition_to_queued(): void
    {
        $backup = Backup::factory()->create(['status' => BackupStatus::Created]);

        (new BackupStateMachine())->transition($backup, BackupStatus::Queued);

        $this->assertSame(BackupStatus::Queued, $backup->fresh()->status);
    }

    public function test_created_cannot_transition_directly_to_verified(): void
    {
        $backup = Backup::factory()->create(['status' => BackupStatus::Created]);

        $this->expectException(InvalidBackupStateTransitionException::class);
        (new BackupStateMachine())->transition($backup, BackupStatus::Verified);
    }

    public function test_verified_can_transition_to_restoring(): void
    {
        $backup = Backup::factory()->create(['status' => BackupStatus::Verified]);

        (new BackupStateMachine())->transition($backup, BackupStatus::Restoring);

        $this->assertSame(BackupStatus::Restoring, $backup->fresh()->status);
    }

    public function test_a_terminal_deleted_backup_cannot_transition_anywhere(): void
    {
        $backup = Backup::factory()->create(['status' => BackupStatus::Deleted]);

        $this->expectException(InvalidBackupStateTransitionException::class);
        (new BackupStateMachine())->transition($backup, BackupStatus::Verified);
    }

    public function test_running_can_only_go_to_verifying_or_failed(): void
    {
        $backup = Backup::factory()->create(['status' => BackupStatus::Running]);

        $this->expectException(InvalidBackupStateTransitionException::class);
        (new BackupStateMachine())->transition($backup, BackupStatus::Restored);
    }
}
