<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 08 §29-31 "Stock Movement / Stock Ledger". Append-only by
// convention (no UPDATE or DELETE is ever performed against this table
// by application code — verified in InventoryService) — corrections are
// represented by a NEW compensating movement (Module 08 "Immutable
// Ledger Principle" per this milestone's prompt), never by editing a
// historical row. High-volume table (ADR-003): lean row width, indexes
// limited to actual query patterns.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->string('type', 24); // StockMovementType
            $table->integer('quantity'); // signed: positive for inbound, negative for outbound — see StockMovementType::isInbound()
            $table->unsignedInteger('previous_on_hand');
            $table->unsignedInteger('new_on_hand');
            $table->string('reference_type', 64)->nullable(); // e.g. "manual_adjustment", "reservation" — free-form until Orders/Purchasing introduce real reference types
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['store_id', 'idempotency_key'], 'uniq_stock_movements_store_id_idempotency_key');
            $table->index(['store_id', 'inventory_id', 'created_at'], 'idx_stock_movements_store_id_inventory_id_created_at');
            $table->index(['store_id', 'type'], 'idx_stock_movements_store_id_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
