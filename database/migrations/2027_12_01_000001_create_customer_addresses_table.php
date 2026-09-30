<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase B25 — a signed-in customer's saved addresses (Module 10 customer
// accounts). Orders keep their own address snapshots, so editing or
// deleting an address never rewrites an order.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_customer_addresses_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('label', 50)->nullable();
            $table->string('name', 120);
            $table->string('phone', 32)->nullable();
            $table->string('line1', 190);
            $table->string('line2', 190)->nullable();
            $table->string('city', 100);
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country', 2);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['store_id', 'customer_id'], 'idx_customer_addresses_store_customer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
