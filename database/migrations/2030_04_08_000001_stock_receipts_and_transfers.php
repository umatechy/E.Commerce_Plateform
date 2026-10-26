<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase P4a — advanced inventory (Module 08 §71 Business: transfers,
 * purchase receiving, advanced stock reports; Module 04 §8 `inventory.advanced`).
 *
 * - `stock_receipts` / `_lines`: stock received from a supplier into one
 *   location. Quantities only — the platform keeps no inventory costing
 *   (owner decision).
 * - `stock_transfers` / `_lines`: stock sent from one location to another.
 *   Sent: out of the source, shown as incoming at the destination; received:
 *   into the destination; cancelled while on the way: back to the source.
 *
 * Every stock change itself is a stock movement written by InventoryService
 * (`purchase_in`, `transfer_out`, `transfer_in`), pointing at its document.
 * Store-scoped, purged with the store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_receipts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_stock_receipts_public_id');
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('supplier', 120);
            $table->string('reference', 80)->nullable(); // the supplier's delivery note or invoice number
            $table->string('note', 500)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 100);
            $table->timestamps();
            $table->unique(['store_id', 'number'], 'uniq_stock_receipts_store_number');
            $table->unique(['store_id', 'idempotency_key'], 'uniq_stock_receipts_store_idempotency');
        });

        Schema::create('stock_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->unsignedInteger('quantity');
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_stock_transfers_public_id');
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 12); // in_transit | received | cancelled
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('idempotency_key', 100);
            $table->timestamps();
            $table->unique(['store_id', 'number'], 'uniq_stock_transfers_store_number');
            $table->unique(['store_id', 'idempotency_key'], 'uniq_stock_transfers_store_idempotency');
            $table->index(['store_id', 'status'], 'idx_stock_transfers_store_status');
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->foreignId('to_inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->unsignedInteger('quantity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_receipt_lines');
        Schema::dropIfExists('stock_receipts');
    }
};
