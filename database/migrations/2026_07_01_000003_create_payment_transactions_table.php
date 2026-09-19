<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 12 §8-9 "Payment Attempt / Payment Transaction" — B7's
// documented 2-tier simplification folds both concepts into this ONE
// append-only ledger table (see b7-inspection-findings.md). Never
// updated or deleted by application code — same convention as B4's
// stock_movements and B5's order_timeline_events.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('type', 24); // TransactionType
            $table->string('status', 16); // TransactionStatus
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('provider_transaction_reference')->nullable(); // Module 12 §10 — kept SEPARATE from internal id
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); // set for staff-initiated actions (manual confirmation, refund); null for gateway/webhook-driven ones
            $table->string('idempotency_key');
            $table->json('metadata')->nullable(); // redacted, non-sensitive only
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['store_id', 'idempotency_key'], 'uniq_payment_transactions_store_id_idempotency_key');
            $table->index(['store_id', 'payment_id', 'created_at'], 'idx_payment_transactions_store_id_payment_id_created_at');
            $table->index(['store_id', 'type', 'status'], 'idx_payment_transactions_store_id_type_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
