<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B41 — gap G15, part 3 (Module 07 §18–19, §27–37, §41, §44–49,
 * §75–79):
 *
 * - attributes: a group (§28), a unit for numbers (§33), active or not
 *   (§75); a new type `color` (§35) whose values carry a colour code;
 * - attribute_values: slug for addresses (§36, §49), colour code, active;
 * - attribute_sets + attribute_set_items (§44);
 * - category_attributes: per category, which attributes apply, which are
 *   required and which are customer filters (§18–19, §45);
 * - product_attribute_values: a product's specifications (§41), typed:
 *   a chosen value (select / multi-select / colour — one row per value),
 *   a number, a yes/no or a text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->string('group', 80)->nullable()->after('type');
            $table->string('unit', 20)->nullable()->after('group');
            $table->boolean('is_active')->default(true)->after('unit');
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });

        Schema::table('attribute_values', function (Blueprint $table) {
            $table->string('slug', 120)->nullable()->after('normalized_value');
            $table->string('color_code', 7)->nullable()->after('slug');
            $table->boolean('is_active')->default(true)->after('color_code');
            $table->index(['attribute_id', 'slug'], 'idx_attribute_values_attribute_slug');
        });
        // Existing values get their slug.
        foreach (DB::table('attribute_values')->get(['id', 'normalized_value']) as $value) {
            DB::table('attribute_values')->where('id', $value->id)->update(['slug' => \Illuminate\Support\Str::slug((string) $value->normalized_value) ?: 'v'.$value->id]);
        }

        Schema::create('attribute_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('name', 120);
            $table->timestamps();

            $table->unique(['store_id', 'name'], 'uniq_attribute_sets_store_name');
        });

        Schema::create('attribute_set_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_set_id')->constrained('attribute_sets')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);

            $table->unique(['attribute_set_id', 'attribute_id'], 'uniq_attribute_set_items');
        });

        Schema::create('category_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filter')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'attribute_id'], 'uniq_category_attributes');
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->nullable()->constrained('attribute_values')->cascadeOnDelete();
            $table->decimal('number_value', 18, 4)->nullable();
            $table->boolean('bool_value')->nullable();
            $table->string('text_value', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'attribute_id'], 'idx_pav_product_attribute');
            $table->index(['attribute_id', 'attribute_value_id'], 'idx_pav_attribute_value');
            $table->index(['attribute_id', 'number_value'], 'idx_pav_attribute_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('category_attributes');
        Schema::dropIfExists('attribute_set_items');
        Schema::dropIfExists('attribute_sets');
        Schema::table('attribute_values', function (Blueprint $table) {
            $table->dropIndex('idx_attribute_values_attribute_slug');
            $table->dropColumn(['slug', 'color_code', 'is_active']);
        });
        Schema::table('attributes', function (Blueprint $table) {
            $table->dropColumn(['group', 'unit', 'is_active', 'sort_order']);
        });
    }
};
