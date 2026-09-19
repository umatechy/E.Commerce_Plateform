<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 09 §4 "Order Entity". Money as integer minor units + currency
// (ADR-003, unchanged convention). order_number is the customer-facing
// identifier (§5) — distinct from both the internal `id` and the
// platform-wide `public_id` convention every other resource uses;
// Order deliberately has BOTH public_id (API addressing, consistent
// with the rest of the platform) AND order_number (human-readable,
// store-scoped, e.g. "ORD-000123") because Module 09 explicitly
// requires a THIRD identifier distinct from the other two.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_orders_public_id');
            $table->string('order_number', 32);
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();

            // Guest snapshot (Module 09 §8: guest orders retain the info
            // needed for fulfillment/communication without a permanent
            // account) — populated when customer_id is null.
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();

            $table->string('status', 24)->default('pending_confirmation');
            $table->string('payment_status', 24)->default('unpaid');
            $table->string('fulfillment_status', 24)->default('unfulfilled');
            $table->string('source', 24)->default('storefront');

            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_total_minor')->default(0); // Module 09 §12 — populated by a future Promotions module; 0 until then
            $table->bigInteger('tax_total_minor')->default(0); // populated by a future Tax module; 0 until then
            $table->bigInteger('shipping_total_minor')->default(0); // populated by a future Shipping module; 0 until then
            $table->bigInteger('grand_total_minor')->default(0);

            // Address snapshots (Module 09 §4/§10) — plain JSON for B5
            // (no dedicated Address model exists yet; Module 10/11 may
            // introduce one without needing to migrate this column's
            // shape, since it is captured as a point-in-time snapshot
            // regardless of where the structured source data later lives).
            $table->json('billing_address_snapshot')->nullable();
            $table->json('shipping_address_snapshot')->nullable();

            $table->text('notes')->nullable();
            $table->string('idempotency_key');

            $table->string('cancellation_reason', 32)->nullable();
            $table->text('cancellation_note')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'order_number'], 'uniq_orders_store_id_order_number');
            $table->unique(['store_id', 'idempotency_key'], 'uniq_orders_store_id_idempotency_key');
            $table->index(['store_id', 'status'], 'idx_orders_store_id_status');
            $table->index(['store_id', 'customer_id'], 'idx_orders_store_id_customer_id');
            $table->index(['store_id', 'created_at'], 'idx_orders_store_id_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
