<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Phase B30 (gap G4) — scheduled backups, restore rehearsal and critical
// alerts (Module 23 §9, §15–18, §29–30, §47; SRS BKP-001, BKP-007,
// HEALTH-005, TEST-011).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            // Which retention rule applies: daily, monthly, manual, pre_change.
            $table->string('retention_tier', 16)->default('manual')->after('initiated_by_user_id');
            // One scheduled backup per period: "platform:daily:2026-10-01".
            // The unique index is what makes an overlapping scheduler run or
            // a retried job unable to create a second one.
            $table->string('schedule_key', 64)->nullable()->after('retention_tier');
            $table->string('compression', 16)->nullable()->after('is_encrypted');
            $table->timestamp('started_at')->nullable()->after('failure_reason');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            // The last time the STORED artifact's checksum was recomputed and matched.
            $table->timestamp('last_checked_at')->nullable()->after('verified_at');
            $table->string('request_id', 64)->nullable()->after('expires_at'); // SRS API-011 correlation

            $table->unique('schedule_key', 'uniq_backups_schedule_key');
        });

        Schema::table('backup_restore_jobs', function (Blueprint $table) {
            // 'production' replaces live data; 'rehearsal' restores into a
            // throw-away database and never touches live data.
            $table->string('mode', 16)->default('production')->after('backup_id');
            $table->string('reference', 120)->nullable()->after('mode'); // incident / change reference
            $table->json('report')->nullable()->after('failure_reason'); // rehearsal checks and timings
            $table->unsignedInteger('duration_ms')->nullable()->after('completed_at');
            $table->string('request_id', 64)->nullable()->after('duration_ms');

            $table->index(['mode', 'status'], 'idx_backup_restore_jobs_mode_status');
        });

        // Platform alerts (a failed platform backup) belong to no store,
        // and neither do the delivery attempts of such a message.
        foreach (['notification_messages', 'notification_delivery_attempts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('store_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        DB::table('notification_delivery_attempts')->whereNull('store_id')->delete();
        DB::table('notification_messages')->whereNull('store_id')->delete();

        foreach (['notification_delivery_attempts', 'notification_messages'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('store_id')->nullable(false)->change();
            });
        }

        Schema::table('backup_restore_jobs', function (Blueprint $table) {
            $table->dropIndex('idx_backup_restore_jobs_mode_status');
            $table->dropColumn(['mode', 'reference', 'report', 'duration_ms', 'request_id']);
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->dropUnique('uniq_backups_schedule_key');
            $table->dropColumn(['retention_tier', 'schedule_key', 'compression', 'started_at', 'completed_at', 'last_checked_at', 'request_id']);
        });
    }
};
