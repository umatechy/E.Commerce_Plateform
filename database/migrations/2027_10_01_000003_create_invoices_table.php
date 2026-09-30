<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 29 (Phase B23) — platform-to-store invoices.
//
// billing_sequences hands out gap-free invoice numbers: the counter row is
// locked and incremented in the same transaction that inserts the
// invoice, so a rolled-back issue also rolls back its number.
//
// An invoice is a financial record: amounts are snapshotted at issue
// time and never recomputed; it is voided, never deleted (ADR-003).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_sequences', function (Blueprint $table) {
            $table->string('key', 32)->primary();
            $table->unsignedBigInteger('next_value');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_invoices_public_id');
            $table->string('number', 32)->unique('uniq_invoices_number');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->string('status', 16); // InvoiceStatus
            $table->string('billing_reason', 32); // BillingReason
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedInteger('tax_rate_bps')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('amount_paid_minor')->default(0);
            $table->json('bill_to'); // store name + billing email at issue time
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('issued_at');
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            // One invoice per subscription period: the billing engine can
            // run twice (or concurrently) without double-billing.
            $table->unique(['subscription_id', 'period_start'], 'uniq_invoices_subscription_period');
            $table->index(['store_id', 'issued_at'], 'idx_invoices_store_id_issued_at');
            $table->index(['status', 'due_at'], 'idx_invoices_status_due_at');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_amount_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_invoice_payments_public_id');
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('method', 32); // InvoicePaymentMethod
            $table->string('reference', 128)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('received_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            // A retried "record payment" request never records it twice.
            $table->string('idempotency_key', 128)->unique('uniq_invoice_payments_idempotency_key');
            $table->timestamps();

            $table->index(['store_id', 'received_at'], 'idx_invoice_payments_store_id_received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('billing_sequences');
    }
};
