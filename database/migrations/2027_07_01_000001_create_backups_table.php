<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 23 §16 "Backup Manifest" / §17 "Backup States" — see
// docs/development/b19-inspection-findings.md for the full data-
// classification and scope decisions. `store_id` is nullable: NULL for
// a platform-wide backup, set for a store-scoped VIEW of the same
// underlying platform dump (see "Architectural Decision — One Full-
// Platform Dump" — the artifact itself is always a full database
// snapshot; store_id here is a metadata/authorization association, not
// a claim that the artifact contains only that store's rows).
// `storage_path` is ALWAYS server-generated (BackupStorageAdapter),
// never client-supplied — see security review. `manifest` holds the
// Module 23 §16 fields that don't need their own indexed column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_backups_public_id');
            $table->string('scope', 16); // BackupScope
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            $table->string('status', 20)->default('created'); // BackupStatus
            $table->string('initiated_by', 32); // BackupInitiator — 32, not 16: 'pre_restore_safety' is 18 chars (error 1406 on first real run)
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_disk', 32)->nullable();
            $table->string('storage_path')->nullable(); // opaque, server-generated — never client input
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->json('manifest')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status'], 'idx_backups_store_id_status');
            $table->index(['scope', 'status', 'expires_at'], 'idx_backups_scope_status_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
