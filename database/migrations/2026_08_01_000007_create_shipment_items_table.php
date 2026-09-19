<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 13 §11 (this milestone's Step 11) "Shipment Items" — quantity
// shipped must never exceed the OrderItem's ordered quantity, validated
// in ShipmentService (application layer — see class docblock for why a
// DB constraint alone cannot express "sum across multiple shipments for
// the same order_item_id must not exceed order_items.quantity").
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index(['store_id', 'shipment_id'], 'idx_shipment_items_store_id_shipment_id');
            $table->index(['store_id', 'order_item_id'], 'idx_shipment_items_store_id_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_items');
    }
};
