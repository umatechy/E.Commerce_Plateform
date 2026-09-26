<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 19 §6 "Domain Record". `normalized_hostname` (lowercase,
// no trailing dot, no scheme/port) is the ONLY column ever used for
// lookup/uniqueness — `hostname` preserves the originally-submitted
// display form. Uniqueness is GLOBAL (not per-store) on
// normalized_hostname: a verified/active hostname must never
// simultaneously resolve to two different stores (Module 19 §10,
// Non-Negotiable) — enforced at the database level, not just in
// application code.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_domains_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('hostname');
            $table->string('normalized_hostname');
            $table->string('domain_type', 24); // DomainType
            $table->string('status', 24)->default('pending'); // DomainStatus
            $table->boolean('is_primary')->default(false);
            $table->string('verification_token', 64)->nullable();
            $table->timestamp('verification_token_expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('ssl_status', 16)->default('none'); // SslStatus
            $table->timestamps();

            $table->unique('normalized_hostname', 'uniq_domains_normalized_hostname');
            $table->index(['store_id', 'status'], 'idx_domains_store_id_status');
            $table->index(['store_id', 'is_primary'], 'idx_domains_store_id_is_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
