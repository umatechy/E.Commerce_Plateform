<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 31 §9-10/§44 "API Key Architecture / Credential Security" —
// Non-Negotiable: `key_hash` is a one-way SHA-256 hash, the plaintext
// secret is NEVER stored. `key_prefix` is the non-secret lookup value
// (avoids a full-table hash comparison per request). `store_id` is
// DENORMALIZED from the owning Application (read-only copy, set once
// at creation) purely for fast, direct tenant-scoping on this
// high-frequency-read table — the Application row remains the single
// authoritative owner.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_api_keys_public_id');
            $table->foreignId('developer_application_id')->constrained('developer_applications')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('key_prefix', 16)->unique();
            $table->string('key_hash', 64); // sha256 hex digest
            $table->json('scopes'); // list<ApiScope value>
            $table->string('status', 16)->default('active'); // ApiKeyStatus
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status'], 'idx_api_keys_store_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
