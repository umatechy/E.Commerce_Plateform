<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 14 §7-10 — one row per targeted product/category/brand.
// target_type mirrors the promotion's own target_scope (product/
// category/brand — never 'order', which needs no rows here).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('target_type', 16); // 'product' | 'category' | 'brand'
            $table->unsignedBigInteger('target_id'); // internal id of the Product/Category/Brand row
            $table->timestamps();

            $table->unique(['promotion_id', 'target_type', 'target_id'], 'uniq_promotion_targets_promotion_id_type_id');
            $table->index(['store_id', 'target_type', 'target_id'], 'idx_promotion_targets_store_id_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_targets');
    }
};
