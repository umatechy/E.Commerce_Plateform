<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 32 (Phase B22) — the platform's durable, queryable audit trail,
// replacing the file-only Log::channel('audit') of B2-B21.
//
// Append-only and tamper-evident: every row belongs to one hash chain
// (one per store, one for platform-level events) and stores the SHA-256
// of its own canonical content plus the previous row's hash. Rewriting
// or deleting a row breaks every later hash in that chain, which
// `audit:verify` detects. audit_chain_heads serializes appends per
// chain (row lock) and remembers the retention anchor after pruning.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_audit_logs_public_id');
            // Nullable: platform-level events belong to no store. Restrict,
            // never cascade/null: the store id is part of the hashed content.
            $table->foreignId('store_id')->nullable()->constrained('stores')->restrictOnDelete();
            $table->string('chain_key', 32);
            $table->unsignedBigInteger('sequence');
            $table->string('action', 128);
            $table->string('actor_type', 16); // AuditActorType
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_public_id', 26)->nullable();
            $table->string('actor_label', 191)->nullable(); // snapshot, survives renames/deletion
            $table->unsignedBigInteger('impersonator_id')->nullable();
            $table->string('impersonator_label', 191)->nullable();
            $table->string('surface', 16); // AuditSurface
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_public_id', 26)->nullable();
            // Canonical JSON kept as TEXT, not JSON: MySQL's JSON type
            // re-orders keys, which would change the hashed bytes.
            $table->longText('context');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamp('created_at');

            $table->unique(['chain_key', 'sequence'], 'uniq_audit_logs_chain_key_sequence');
            $table->index(['store_id', 'created_at'], 'idx_audit_logs_store_id_created_at');
            $table->index(['action', 'created_at'], 'idx_audit_logs_action_created_at');
            $table->index(['actor_type', 'actor_id'], 'idx_audit_logs_actor');
            $table->index(['subject_type', 'subject_id'], 'idx_audit_logs_subject');
        });

        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->string('chain_key', 32)->primary();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->char('last_hash', 64)->nullable();
            // After pruning: the last removed entry, so verification can
            // start from the oldest kept row and still prove continuity.
            $table->unsignedBigInteger('anchor_sequence')->default(0);
            $table->char('anchor_hash', 64)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_heads');
        Schema::dropIfExists('audit_logs');
    }
};
