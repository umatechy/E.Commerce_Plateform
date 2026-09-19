<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 09 §11 "Order Line Item" + §10 "Order Snapshot Principle".
// product_id/product_variant_id are RETAINED as internal references
// (nullable — a referenced product could theoretically be hard-deleted
// far in the future, though B3 only soft-deletes) while every
// customer-visible/calculation-relevant field is a SNAPSHOT captured at
// order-creation time, per the module's explicit "later catalog changes
// must not rewrite historical order information" rule.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            // Snapshots — authoritative for this order forever, regardless
            // of later catalog changes.
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot')->nullable();
            $table->json('variant_snapshot')->nullable(); // e.g. {"color": "black", "size": "M"}

            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('line_total_minor');

            $table->string('fulfillment_status', 24)->default('unfulfilled');
            $table->timestamps();

            $table->index(['store_id', 'order_id'], 'idx_order_items_store_id_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
