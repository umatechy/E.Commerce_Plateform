<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 11 §64-71 "Wishlist". Authenticated-customer-only in B6
// (§69: guest wishlist is client-side/local-storage, not persisted
// server-side) — customer_id is therefore NOT nullable here, unlike
// carts.customer_id. Only a single "default wishlist" per customer
// exists (§65) — this table has no separate `wishlists` parent table,
// documented simplification (see b6-inspection-findings.md).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['customer_id', 'product_id', 'product_variant_id'],
                'uniq_wishlist_items_customer_id_product_id_product_variant_id'
            );
            $table->index(['store_id', 'customer_id'], 'idx_wishlist_items_store_id_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
