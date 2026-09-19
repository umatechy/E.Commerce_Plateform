<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 13 §38 "Shipping Rate Engine" — one rate row per
// (zone, method). base_cost_minor is used directly for flat_rate/free/
// store_pickup/local_delivery; per_unit_cost_minor + unit_threshold are
// used ONLY for weight_based (per kg) and price_based (per currency
// unit over a threshold) — a documented simplification of a full tiered
// rate table (no ShippingRateRule sub-table in B8, see inspection
// findings), sufficient for a single linear rate per zone+method.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('shipping_zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->foreignId('shipping_method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('base_cost_minor')->default(0);
            $table->bigInteger('per_unit_cost_minor')->nullable();
            $table->decimal('unit_threshold', 10, 3)->nullable(); // weight (kg) or price (major units), depending on method type
            $table->timestamps();

            $table->unique(['shipping_zone_id', 'shipping_method_id'], 'uniq_shipping_rates_zone_id_method_id');
            $table->index(['store_id'], 'idx_shipping_rates_store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
