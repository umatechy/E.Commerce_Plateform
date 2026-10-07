<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner request 2026-10-07 (owner decision 15) — Module 05 §14, §27, §55
 * (product reviews, rating on cards, aggregate rating), Module 10 §31,
 * Module 15 §32: Business and Premium storefronts show a product's rating
 * and how many were sold.
 *
 * - product_reviews: a customer's rating (1–5) and text for a product they
 *   bought; moderated by the store (pending → approved / rejected); the
 *   store may reply. One review per customer and product.
 * - products.review_count / rating_total: the approved reviews, kept by
 *   ReviewService (an average without floating point: total ÷ count).
 * - Features reviews.product and products.units_sold: Business and Premium.
 * - Permission reviews.manage: Owner, Administrator, Manager, Content &
 *   Marketing.
 */
return new class extends Migration
{
    private const FEATURES = ['reviews.product', 'products.units_sold'];

    private const PACKAGES = ['basic' => false, 'business' => true, 'premium' => true];

    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('body');
            $table->string('author_name', 80);
            $table->string('status', 16)->default('pending');
            $table->boolean('verified_purchase')->default(false);
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'customer_id'], 'uniq_product_reviews_product_customer');
            $table->index(['store_id', 'status', 'created_at'], 'idx_product_reviews_store_status');
            $table->index(['product_id', 'status'], 'idx_product_reviews_product_status');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('rating_total')->default(0);
        });

        foreach (self::PACKAGES as $code => $enabled) {
            $packageId = DB::table('packages')->where('code', $code)->value('id');
            if ($packageId === null) {
                continue;
            }
            foreach (self::FEATURES as $key) {
                if (! DB::table('package_entitlements')->where('package_id', $packageId)->where('key', $key)->exists()) {
                    DB::table('package_entitlements')->insert([
                        'package_id' => $packageId, 'key' => $key, 'type' => 'feature',
                        'boolean_value' => $enabled, 'is_unlimited' => false, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        DB::table('permissions')->insertOrIgnore([['key' => 'reviews.manage', 'group' => 'catalog', 'description' => 'Approve, reject, reply to and delete product reviews']]);
        $permission = DB::table('permissions')->where('key', 'reviews.manage')->value('id');
        DB::table('roles')->where('is_system', true)->whereIn('slug', ['administrator', 'manager', 'content-marketing'])->orderBy('id')
            ->chunkById(500, fn ($roles) => DB::table('permission_role')->insertOrIgnore(
                $roles->map(fn ($role) => ['role_id' => $role->id, 'permission_id' => $permission])->all(),
            ));
    }

    public function down(): void
    {
        $permission = DB::table('permissions')->where('key', 'reviews.manage')->value('id');
        DB::table('permission_role')->where('permission_id', $permission)->delete();
        DB::table('permissions')->where('key', 'reviews.manage')->delete();
        DB::table('package_entitlements')->whereIn('key', self::FEATURES)->delete();
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['review_count', 'rating_total']));
        Schema::dropIfExists('product_reviews');
    }
};
