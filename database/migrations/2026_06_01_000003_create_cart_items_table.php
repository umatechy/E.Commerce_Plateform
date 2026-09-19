<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 11 §10 "Cart Item" + §13-14 "Cart Price / Price Changes".
// price_at_add_minor is a CHANGE-DETECTION value only, never
// authoritative (Module 11 Final Rule #5: "Cart prices are
// provisional") — CartService always recomputes the live price from
// Product/ProductVariant at retrieval and checkout time.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->bigInteger('price_at_add_minor'); // display/change-detection only — see docblock
            $table->timestamps();

            $table->unique(
                ['cart_id', 'product_id', 'product_variant_id'],
                'uniq_cart_items_cart_id_product_id_product_variant_id'
            );
            $table->index(['store_id', 'cart_id'], 'idx_cart_items_store_id_cart_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
