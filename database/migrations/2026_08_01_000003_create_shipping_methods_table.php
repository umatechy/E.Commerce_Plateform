<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 13 §15-24.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 24); // ShippingMethodType
            $table->boolean('is_active')->default(true);
            $table->bigInteger('free_shipping_threshold_minor')->nullable(); // Module 13 §17 — null means "not eligible via order-value threshold"
            $table->timestamps();

            $table->index(['store_id', 'is_active'], 'idx_shipping_methods_store_id_is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_methods');
    }
};
