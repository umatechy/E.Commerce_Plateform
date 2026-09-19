<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 06 §9. store_id is denormalized onto variants (rather than
// resolved via product_id -> products.store_id on every query) so
// BelongsToTenant's global scope and tenant-aware indexing (ADR-003)
// apply directly to this table too, consistent with every other
// tenant-owned table in the platform.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_product_variants_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->bigInteger('price_minor')->nullable();
            $table->bigInteger('sale_price_minor')->nullable();
            $table->bigInteger('cost_price_minor')->nullable();
            $table->decimal('weight', 10, 3)->nullable();
            $table->string('status', 16)->default('active');
            $table->json('option_values')->nullable(); // e.g. {"color": "black", "size": "M"} — display/lookup only, not queried by value in B3
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'sku'], 'uniq_product_variants_store_id_sku');
            $table->index(['store_id', 'product_id'], 'idx_product_variants_store_id_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
