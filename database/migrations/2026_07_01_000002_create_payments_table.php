<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 12 §5-6 "Payment Entity / Payment vs Order". One Payment
// aggregate per Order (Module 12 §6: "One Order may have: One
// Payment... Multiple Payment Attempts... Multiple Transactions" —
// attempts/transactions live in payment_transactions below, not as
// separate Payment rows). Money as integer minor units + currency
// (ADR-003, unchanged convention).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_payments_public_id');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('method', 24); // PaymentMethod
            $table->string('status', 24)->default('created'); // PaymentStatus
            $table->bigInteger('amount_minor'); // server-derived from Order.grand_total_minor at creation — never client input
            $table->char('currency', 3);
            $table->string('provider_payment_reference')->nullable(); // e.g. gateway's own payment/intent ID
            $table->string('idempotency_key');
            $table->json('metadata')->nullable(); // non-sensitive gateway response fragments only — see PaymentService docblock on redaction
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'order_id'], 'uniq_payments_store_id_order_id'); // one Payment aggregate per Order (B7 scope decision)
            $table->unique(['store_id', 'idempotency_key'], 'uniq_payments_store_id_idempotency_key');
            $table->index(['store_id', 'status'], 'idx_payments_store_id_status');
            $table->index(['store_id', 'customer_id'], 'idx_payments_store_id_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
