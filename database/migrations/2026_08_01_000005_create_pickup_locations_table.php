<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 13 §27-28 "Store Pickup / Pickup Location".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_locations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_pickup_locations_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('name');
            $table->text('address');
            $table->string('contact')->nullable();
            $table->text('instructions')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['store_id', 'status'], 'idx_pickup_locations_store_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_locations');
    }
};
