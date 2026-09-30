<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 24 (Phase B21) — periodic store-health results. Written only by
// store-health:snapshot (hourly); append-only history, pruned after
// monitoring.store_health.snapshot_retention_days. The Super Admin
// overview reads the latest row per store instead of recomputing every
// store's health on each request.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('overall_status', 16); // HealthStatus
            $table->json('checks');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_id', 'created_at'], 'idx_store_health_snapshots_store_id_created_at');
            $table->index(['overall_status', 'created_at'], 'idx_store_health_snapshots_status_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_health_snapshots');
    }
};
