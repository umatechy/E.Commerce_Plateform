<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B53 (SRS API-007; Module 31 §42; Master Development Prompt §16) —
 * the Idempotency-Key header for retry-sensitive writes: who asked
 * (tenant and actor), which call, the key, a fingerprint of the request,
 * and the answer to replay. Kept 24 hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('actor', 64);            // user:5 | customer:9 | apikey:3
            $table->string('request', 255);         // METHOD /path
            $table->string('idempotency_key', 100);
            $table->char('fingerprint', 64);        // SHA-256 of method, path and body
            $table->unsignedSmallInteger('status_code')->nullable(); // null while the first request is still running
            $table->longText('response_body')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->unique(['actor', 'request', 'idempotency_key'], 'idempotency_keys_unique');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
