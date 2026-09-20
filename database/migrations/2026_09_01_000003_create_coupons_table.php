<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 14 §23-26. `code_normalized` is the actual comparison/lookup
// key (uppercased, trimmed — Module 14 §24: "case-insensitive codes
// where configured"; B9 always normalizes, documented decision, see
// CouponService); `code` preserves the original display casing the
// merchant typed. Coupon usage limits are independent of (and layered
// on top of) the parent Promotion's own limits.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('code');
            $table->string('code_normalized');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('customer_usage_limit')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'code_normalized'], 'uniq_coupons_store_id_code_normalized');
            $table->index(['promotion_id'], 'idx_coupons_promotion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
