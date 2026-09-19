<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 06 §33 "Catalog Organization" — a product may belong to
// several categories in addition to its primary_category_id (products
// table). Tenant isolation inherited from both sides' store_id (both
// FKs point at rows the application already keeps in the same store —
// enforced in ProductController, not re-derivable from this pivot
// alone).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();

            $table->unique(['product_id', 'category_id'], 'uniq_product_category_product_id_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_category');
    }
};
