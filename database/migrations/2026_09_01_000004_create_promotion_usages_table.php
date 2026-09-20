<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 14 §45/§60/§69 "Usage Counting / Discount Adjustments /
// Promotion Audit" — append-only ledger, doubling as both the usage-
// counting record AND the audit trail (same 2-in-1 pattern as Phase
// B7's PaymentTransaction). One row per successful redemption, created
// in the SAME transaction as the Order (Architectural Decision: usage
// counted at Order Created — see inspection findings).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('promotion_id')->constrained('promotions')->restrictOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->bigInteger('discount_amount_minor');
            $table->char('currency', 3);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['order_id', 'promotion_id'], 'uniq_promotion_usages_order_id_promotion_id'); // one usage row per promotion per order — B9's non-stacking decision makes this naturally 0-or-1 per order anyway
            $table->index(['store_id', 'promotion_id', 'customer_id'], 'idx_promotion_usages_store_id_promotion_id_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_usages');
    }
};
