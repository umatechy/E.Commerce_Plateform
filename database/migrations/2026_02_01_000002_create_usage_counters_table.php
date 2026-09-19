<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 04 §11-12 "Usage Tracking / Usage Counter Strategy". Tenant-owned,
// high-volume-shaped table (ADR-003 "High-volume tables"): lean row width,
// one row per (store, metric, period). The unique constraint IS the
// concurrency-safety mechanism (Module 04 "Concurrency" section) — every
// write goes through INSERT ... ON DUPLICATE KEY UPDATE against this
// constraint, atomic at the MySQL level (see UsageTrackingService).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('metric_key', 64); // e.g. "max_products"
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(
                ['store_id', 'metric_key', 'period_start'],
                'uniq_usage_counters_store_id_metric_key_period_start'
            );
            $table->index(['store_id', 'metric_key'], 'idx_usage_counters_store_id_metric_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
