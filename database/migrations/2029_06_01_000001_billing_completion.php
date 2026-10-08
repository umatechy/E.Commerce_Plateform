<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B47 — billing completion (gap G21; Module 29 §42–49, §75–82, §92–94):
 *
 * - subscriptions.scheduled_package_id: a downgrade waiting for the period end;
 * - invoices: credit applied from the account balance, amounts credited by
 *   credit notes, and the document template version (§76);
 * - invoice_lines.kind: a charge or a credit (the unused part of a plan);
 * - credit_notes (§43, immutable once issued; §92 approval above a threshold);
 * - billing_credits: the store's account credit ledger (§45–46), + added,
 *   − used, never edited;
 * - payment_notices: a store telling Umar Techy it paid by transfer, for staff
 *   to confirm (§73–74, §79).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('scheduled_package_id')->nullable()->after('package_id')->constrained('packages')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_applied_minor')->default(0)->after('tax_minor');
            $table->unsignedBigInteger('amount_credited_minor')->default(0)->after('amount_paid_minor');
            $table->unsignedSmallInteger('document_version')->default(1);
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('kind', 8)->default('charge')->after('invoice_id');
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_credit_notes_public_id');
            $table->string('number', 32)->nullable()->unique('uniq_credit_notes_number'); // given when issued
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('status', 24); // pending_approval | issued | rejected
            $table->string('settlement', 24); // refund | account_credit | reduce_balance
            $table->string('reason', 500);
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('total_minor');
            $table->json('lines');
            $table->string('refund_method', 32)->nullable();
            $table->string('refund_reference', 128)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedSmallInteger('document_version')->default(1);
            $table->string('idempotency_key', 128)->unique('uniq_credit_notes_idempotency_key');
            $table->timestamps();
            $table->index(['store_id', 'created_at'], 'idx_credit_notes_store');
            $table->index(['status'], 'idx_credit_notes_status');
        });

        Schema::create('billing_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->bigInteger('amount_minor'); // + added, − used
            $table->char('currency', 3);
            $table->string('source', 24); // credit_note | proration | invoice
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete(); // the invoice it paid towards
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['store_id', 'currency'], 'idx_billing_credits_store_currency');
        });

        Schema::create('payment_notices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_payment_notices_public_id');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('method', 32);
            $table->string('reference', 128);
            $table->date('paid_on');
            $table->string('note', 500)->nullable();
            $table->string('status', 16)->default('pending'); // pending | approved | rejected
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('invoice_payment_id')->nullable()->constrained('invoice_payments')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'idx_payment_notices_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notices');
        Schema::dropIfExists('billing_credits');
        Schema::dropIfExists('credit_notes');
        Schema::table('invoice_lines', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['credit_applied_minor', 'amount_credited_minor', 'document_version']));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropConstrainedForeignId('scheduled_package_id'));
    }
};
