<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 14 §4-22. Money as integer minor units + currency (ADR-003,
// unchanged convention). A promotion with target_scope='order' applies
// to the whole cart with no promotion_targets rows; product/category/
// brand-scoped promotions have one or more promotion_targets rows.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_promotions_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 24); // PromotionType
            $table->string('target_scope', 16)->default('order'); // PromotionTargetScope
            $table->string('status', 16)->default('draft'); // PromotionStatus
            $table->unsignedInteger('percentage_value')->nullable(); // 1-100, for type=percentage
            $table->bigInteger('fixed_amount_minor')->nullable(); // for type=fixed_amount
            $table->char('currency', 3)->nullable(); // required for fixed_amount; null for percentage/free_shipping (currency-agnostic)
            $table->bigInteger('min_order_value_minor')->nullable(); // Module 14 §39
            $table->bigInteger('max_discount_minor')->nullable(); // Module 14 §40 — caps a percentage discount's absolute value
            $table->boolean('requires_coupon')->default(false); // Module 14 §28 — false = automatic (no code)
            $table->unsignedInteger('priority')->default(0); // Module 14 §31 — higher wins ties deterministically
            $table->unsignedInteger('usage_limit')->nullable(); // Module 14 §44 — global; null = unlimited
            $table->unsignedInteger('used_count')->default(0); // atomically incremented — see PromotionService
            $table->unsignedInteger('customer_usage_limit')->nullable(); // Module 14 §43; null = unlimited
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status'], 'idx_promotions_store_id_status');
            $table->index(['store_id', 'requires_coupon'], 'idx_promotions_store_id_requires_coupon');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
