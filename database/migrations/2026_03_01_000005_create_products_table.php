<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 06 §5, §20-27. Money stored as integer minor units (ADR-003).
// cost_price_minor is permission-gated at the Resource layer, never
// exposed to customers (Module 06 §25).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_products_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('type', 16)->default('simple'); // ProductType
            $table->string('name');
            $table->string('slug');
            $table->string('sku')->nullable();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('visibility', 16)->default('hidden');
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('primary_category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->bigInteger('price_minor')->nullable();
            $table->bigInteger('sale_price_minor')->nullable();
            $table->bigInteger('cost_price_minor')->nullable();
            $table->char('currency', 3)->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'slug'], 'uniq_products_store_id_slug');
            $table->unique(['store_id', 'sku'], 'uniq_products_store_id_sku');
            $table->index(['store_id', 'status'], 'idx_products_store_id_status');
            $table->index(['store_id', 'brand_id'], 'idx_products_store_id_brand_id');
            $table->index(['store_id', 'primary_category_id'], 'idx_products_store_id_primary_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
