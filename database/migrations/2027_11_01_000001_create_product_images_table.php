<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 05 / Module 06 (Phase B24) — product images for the storefront.
// Files live on the configured public disk; this table is the ordered,
// tenant-owned index of them. Every upload is re-encoded server-side, so
// what is stored is always a clean image without embedded metadata.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_product_images_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // Optional: the image shown when this variant is selected.
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('alt', 255)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('size_bytes');
            $table->timestamps();

            $table->index(['store_id', 'product_id', 'position'], 'idx_product_images_store_product_position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
