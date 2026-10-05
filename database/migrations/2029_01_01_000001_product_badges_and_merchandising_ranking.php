<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B43 — Module 06 §36 (badges), §37 (featured), §93 (merchandising:
 * sort priority, category priority), Module 05 §19 (default sorting),
 * Module 07 §17 (category product ordering):
 *
 * - badges + product_badge: the store's own badges ("Handmade", "Eid
 *   special"), kept apart from the product's core data (§36). Automatic
 *   badges (new, sale, bestseller, low stock, featured, out of stock) are
 *   computed and configured in store settings — no column on products;
 * - products.sort_priority: a merchandising boost (§93), used by the
 *   "featured" order and the search boost;
 * - categories.default_sort: the order a category page opens in (§17).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('label', 40);
            $table->string('tone', 16)->default('accent'); // accent | success | warning | danger | neutral
            $table->smallInteger('priority')->default(50);  // higher shows first
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'label'], 'uniq_badges_store_label');
        });

        Schema::create('product_badge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained('badges')->cascadeOnDelete();

            $table->unique(['product_id', 'badge_id'], 'uniq_product_badge');
            $table->index(['badge_id'], 'idx_product_badge_badge');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->smallInteger('sort_priority')->default(0)->after('is_featured');
            $table->index(['store_id', 'is_featured', 'sort_priority'], 'idx_products_store_merchandising');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('default_sort', 16)->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('default_sort');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_store_merchandising');
            $table->dropColumn('sort_priority');
        });
        Schema::dropIfExists('product_badge');
        Schema::dropIfExists('badges');
    }
};
