<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 23 §16-22 "Restore Architecture" — Non-Negotiable: restore is
// HIGH RISK, never a "download and overwrite" shortcut. `target_store_id`
// records which store REQUESTED/is-the-subject-of the restore for
// audit/authorization purposes (see inspection findings
// "Architectural Decision — Restore Is Platform-Level Only") — the
// actual restore execution is platform-wide, authorized and run only
// by Super Admin. `pre_restore_backup_id` is the safety snapshot taken
// immediately before a destructive restore (Module 23 Phase 19).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_restore_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_id')->constrained('backups')->restrictOnDelete();
            $table->foreignId('target_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('pre_restore_backup_id')->nullable()->constrained('backups')->nullOnDelete();
            $table->string('status', 20)->default('requested'); // RestoreStatus
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('authorized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['target_store_id', 'status'], 'idx_backup_restore_jobs_target_store_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restore_jobs');
    }
};
