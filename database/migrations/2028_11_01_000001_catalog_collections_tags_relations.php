<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B39 — gap G15, part 1 (Module 05 §16, Module 06 §33–38, §60,
 * Module 14 §9):
 *
 * - collections: manual (products picked), rule-based (conditions on
 *   category, brand, tag, price, sale, stock, age) or both, optionally
 *   scheduled (visible only between starts_at and ends_at);
 * - tags: tenant-scoped, lightweight labels on products;
 * - products.is_featured;
 * - product_relations: related, cross-sell, up-sell, alternative.
 *
 * Permission `collections.manage` goes to the Owner (implicit),
 * Administrator and Manager roles, like `categories.manage`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_collections_public_id');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('type', 16)->default('manual'); // manual | rule
            $table->json('rules')->nullable();              // see CollectionRules
            $table->string('match', 8)->default('all');     // all | any
            $table->string('sort', 16)->default('manual');  // manual | newest | price_asc | price_desc | name | best_selling
            $table->string('status', 16)->default('active'); // draft | active
            $table->boolean('is_visible')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'slug'], 'uniq_collections_store_slug');
            $table->index(['store_id', 'status', 'is_visible'], 'idx_collections_store_status');
        });

        Schema::create('collection_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['collection_id', 'product_id'], 'uniq_collection_product');
            $table->index(['product_id'], 'idx_collection_product_product');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('name', 60);
            $table->string('slug', 80);
            $table->timestamps();

            $table->unique(['store_id', 'slug'], 'uniq_tags_store_slug');
        });

        Schema::create('product_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();

            $table->unique(['product_id', 'tag_id'], 'uniq_product_tag');
            $table->index(['tag_id'], 'idx_product_tag_tag');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('visibility');
            $table->index(['store_id', 'is_featured'], 'idx_products_store_featured');
        });

        Schema::create('product_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('related_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('type', 16); // related | cross_sell | up_sell | alternative
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['product_id', 'related_product_id', 'type'], 'uniq_product_relations');
        });

        if (! DB::table('permissions')->where('key', 'collections.manage')->exists()) {
            DB::table('permissions')->insert(['key' => 'collections.manage', 'group' => 'catalog', 'description' => 'Manage collections and tags (Module 06 §34–35 — Phase B39)']);
        }
        $permissionId = DB::table('permissions')->where('key', 'collections.manage')->value('id');
        foreach (DB::table('roles')->whereIn('slug', ['administrator', 'manager', 'content-marketing'])->where('is_system', true)->pluck('id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'collections.manage')->delete();
        Schema::dropIfExists('product_relations');
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_store_featured');
            $table->dropColumn('is_featured');
        });
        Schema::dropIfExists('product_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('collection_product');
        Schema::dropIfExists('collections');
    }
};
