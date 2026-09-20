<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 14 §58-59 "Promotion Snapshot / Promotion Versioning" —
// immutable historical record. promotion_id is kept for traceability
// but every DISPLAY-relevant field is snapshotted here, so editing or
// deleting the live Promotion later never changes what an existing
// Order shows. Same snapshot philosophy as OrderItem's own
// product_name_snapshot (Phase B5).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->string('promotion_name_snapshot');
            $table->string('promotion_type_snapshot', 24);
            $table->string('coupon_code_snapshot')->nullable();
            $table->bigInteger('discount_amount_minor');
            $table->char('currency', 3);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_id', 'order_id'], 'idx_order_promotions_store_id_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_promotions');
    }
};
