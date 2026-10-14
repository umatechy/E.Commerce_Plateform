<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B51 (gap G14; SRS STORE-003, STORE-009, MT-017; Module 03 §7–8,
 * §33–37; Module 30 §10, §140; Module 29 §159–160; Module 35 §42; owner
 * decision 5: closed and suspended stores are kept 90 days).
 *
 * - stores: when the store was closed or suspended, why, until when its
 *   data is kept, what it returns to when reopened, and a legal hold that
 *   stops any deletion.
 * - store_lifecycle_events: every change of a store's life, who made it and
 *   why — kept for as long as the store's financial records (never purged).
 * - store_deletions: an approved permanent deletion: its cooling period, the
 *   backup taken first, what was removed and the final check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('status_before_hold', 32)->nullable()->after('status'); // where reopen / reactivate returns to
            $table->timestamp('closed_at')->nullable()->after('activated_at');
            $table->string('closure_reason', 40)->nullable()->after('closed_at');
            $table->timestamp('suspended_at')->nullable()->after('closure_reason');
            $table->string('suspension_reason', 40)->nullable()->after('suspended_at');
            $table->string('suspension_note', 500)->nullable()->after('suspension_reason');
            $table->timestamp('retention_ends_at')->nullable()->after('suspension_note');
            $table->timestamp('retention_reminded_at')->nullable()->after('retention_ends_at');
            $table->timestamp('archived_at')->nullable()->after('retention_reminded_at');
            $table->boolean('legal_hold')->default(false)->after('archived_at');
            $table->string('legal_hold_reason', 300)->nullable()->after('legal_hold');
            $table->index(['status', 'retention_ends_at'], 'idx_stores_status_retention');
        });

        Schema::create('store_lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->string('reason', 40)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('actor_type', 16); // owner | platform | system
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['store_id', 'id']);
        });

        Schema::create('store_deletions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('status', 16); // scheduled | backing_up | purging | completed | failed | cancelled
            $table->string('reason', 500);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('not_before');
            $table->foreignId('backup_id')->nullable()->constrained('backups')->nullOnDelete();
            $table->json('plan')->nullable();    // rows per table counted when it was requested
            $table->json('removed')->nullable(); // rows and files actually removed
            $table->json('remaining')->nullable(); // the final check: rows left per purged table (all zero)
            $table->string('failure_message', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'not_before']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_deletions');
        Schema::dropIfExists('store_lifecycle_events');
        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex('idx_stores_status_retention');
            $table->dropColumn(['status_before_hold', 'closed_at', 'closure_reason', 'suspended_at', 'suspension_reason', 'suspension_note',
                'retention_ends_at', 'retention_reminded_at', 'archived_at', 'legal_hold', 'legal_hold_reason']);
        });
    }
};
