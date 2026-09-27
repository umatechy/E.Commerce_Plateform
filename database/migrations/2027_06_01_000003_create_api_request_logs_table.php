<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 31 §21 "API Request Logging" — metadata ONLY, Non-Negotiable:
// never request/response bodies, never the Authorization header, never
// the raw API key. Append-only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('api_key_id')->constrained('api_keys')->cascadeOnDelete();
            $table->string('method', 8);
            $table->string('endpoint');
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_id', 'api_key_id', 'created_at'], 'idx_api_request_logs_store_id_api_key_id_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
