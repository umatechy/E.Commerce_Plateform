<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 02 §18 — invitations to join a store's team. Tenant-owned.
 *
 * Only a SHA-256 hash of the token is stored; the token itself exists
 * only in the invitee's email. `pending_email` equals `email` while the
 * invitation is pending and is NULL otherwise, so the unique index allows
 * at most one open invitation per address per store (MySQL has no partial
 * indexes) while keeping any number of past ones as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_invitations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('uniq_store_invitations_public_id');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('email');
            $table->string('pending_email')->nullable();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->char('token_hash', 64)->unique('uniq_store_invitations_token_hash');
            $table->string('status', 16); // pending|accepted|revoked
            $table->timestamp('expires_at');
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'pending_email'], 'uniq_store_invitations_store_id_pending_email');
            $table->index(['store_id', 'status'], 'idx_store_invitations_store_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_invitations');
    }
};
