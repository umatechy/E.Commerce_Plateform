<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 08 §20-22 "Stock Reservation / Reservation Lifecycle / Expiry".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_stock_reservations_public_id');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 16)->default('active'); // ReservationStatus
            $table->string('reference_type', 64)->nullable(); // e.g. future "cart", "order"
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'idempotency_key'], 'uniq_stock_reservations_store_id_idempotency_key');
            $table->index(['store_id', 'status', 'expires_at'], 'idx_stock_reservations_store_id_status_expires_at');
            $table->index(['store_id', 'inventory_id'], 'idx_stock_reservations_store_id_inventory_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
