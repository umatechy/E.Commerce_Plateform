<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B58 (gap G20, part 2; Module 28 §3.3, §17, §25–30, §44, §46, §72,
 * §92, §96, §120) — the platform reseller program, run by Umar Techy.
 *
 * - Tiers hold the commercial terms; a store's terms are copied into its
 *   attribution when it is attributed, so later tier changes never change
 *   what was agreed for that store (§17 "commission snapshot", §2.4).
 * - A reseller never owns the stores they bring (§29): attribution is a
 *   separate record, and the owner is the client.
 * - Commissions come from paid Umar Techy invoices (Module 29 stays the
 *   authority); refunds by credit note take back their share (§25).
 * - Payouts are recorded, not sent; corrections after a payout are
 *   adjustments (§33–39, §69).
 *
 * Platform tables: no tenant scope. The two tables with store_id are
 * financial records and are retained with the store's invoices (§73).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_tiers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 80)->unique();
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('client_discount_bp')->default(0);     // off the public package price, for the reseller's clients (§26)
            $table->unsignedSmallInteger('initial_commission_bp')->default(0);  // on the first paid invoice of a store
            $table->unsignedSmallInteger('recurring_commission_bp')->default(0); // on later invoices: renewals, upgrades (§25)
            $table->unsignedSmallInteger('recurring_months')->nullable();       // null: as long as the store pays; 0: first invoice only
            $table->unsignedSmallInteger('hold_days')->default(30);
            $table->boolean('can_provision')->default(false);                  // may create stores for clients (§28)
            $table->unsignedInteger('max_stores')->nullable();                  // stores a reseller may create
            $table->unsignedBigInteger('payout_threshold_minor')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('resellers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete(); // the account that signs in to the partner portal
            $table->foreignId('reseller_tier_id')->nullable()->constrained('reseller_tiers')->restrictOnDelete(); // set on approval; an applicant has none
            $table->string('code', 20)->unique();
            $table->string('business_name', 160);
            $table->string('contact_name', 120);
            $table->string('email', 190)->unique();
            $table->string('phone', 30)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('status', 12);           // invited | pending | active | paused | rejected | terminated (§6)
            $table->string('status_reason', 300)->nullable();
            $table->string('source', 12);           // invited | applied
            $table->text('application_note')->nullable();
            $table->string('invitation_token_hash', 64)->nullable()->unique();
            $table->timestamp('invitation_expires_at')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('payout_method', 20)->nullable();
            $table->text('payout_details')->nullable(); // encrypted
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('reseller_stores', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('reseller_id')->constrained('resellers')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('source', 12);            // provisioned | link | manual (§17)
            $table->string('provision_key', 80)->nullable(); // the reseller's idempotency key: one store per key (§120)
            $table->json('terms');                   // the tier's terms at attribution
            $table->foreignId('attributed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamp('ended_at')->nullable(); // reassigned by Umar Techy; the store's earlier commissions stay
            $table->string('ended_reason', 300)->nullable();
            $table->timestamps();
            $table->unique(['reseller_id', 'provision_key']);
            $table->index(['store_id', 'ended_at']);
        });

        Schema::create('reseller_payouts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('reseller_id')->constrained('resellers')->restrictOnDelete();
            $table->string('status', 12);            // pending | paid | cancelled
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('method', 20)->nullable();
            $table->string('reference', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['reseller_id', 'status']);
        });

        Schema::create('reseller_commissions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('reseller_id')->constrained('resellers')->restrictOnDelete();
            $table->foreignId('reseller_store_id')->constrained('reseller_stores')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained('invoices')->restrictOnDelete(); // one commission per invoice
            $table->string('kind', 12);              // initial | renewal | upgrade (§25)
            $table->string('status', 12);            // pending | approved | paid | rejected | reversed
            $table->unsignedBigInteger('base_minor'); // the invoice before tax
            $table->unsignedSmallInteger('rate_bp');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('reversed_minor')->default(0);
            $table->char('currency', 3);
            $table->timestamp('hold_until')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('reseller_payout_id')->nullable()->constrained('reseller_payouts')->nullOnDelete();
            $table->timestamps();
            $table->index(['reseller_id', 'status']);
        });

        Schema::create('reseller_adjustments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('reseller_id')->constrained('resellers')->restrictOnDelete();
            $table->foreignId('reseller_commission_id')->nullable()->constrained('reseller_commissions')->nullOnDelete(); // a refund taken back
            $table->bigInteger('amount_minor');      // signed
            $table->char('currency', 3);
            $table->string('reason', 300);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // null: automatic
            $table->foreignId('reseller_payout_id')->nullable()->constrained('reseller_payouts')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index('reseller_id');
        });
    }

    public function down(): void
    {
        foreach (['reseller_adjustments', 'reseller_commissions', 'reseller_payouts', 'reseller_stores', 'resellers', 'reseller_tiers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
