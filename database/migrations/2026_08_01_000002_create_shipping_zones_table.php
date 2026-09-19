<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 13 §8-9 "Shipping Zones / Zone Priority". Precedence is
// deterministic (Final Rule #6): postal_code > city > province >
// country > default (is_default=true) — computed in ShippingRateService
// by counting how many of these columns are set AND match, never by
// arbitrary row order.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->string('country', 2)->nullable(); // ISO 3166-1 alpha-2
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'is_active'], 'idx_shipping_zones_store_id_is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_zones');
    }
};
