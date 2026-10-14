<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B48 (gap G9; Module 12 §8, §20–21, §29, §31–37, §53–55, §59–62,
 * §84–87; Module 30 §26–27; SRS PAY-001…010).
 *
 * - payment_provider_accounts: a store's own merchant account with a
 *   provider (JazzCash, Easypaisa). Credentials are encrypted at rest and
 *   never returned by any API (Module 12 §31–34, §72).
 * - platform_payment_accounts: Umar Techy's own merchant accounts, used to
 *   collect subscription invoices (Module 29; Module 12 §92 keeps the two
 *   money flows apart — the attempt records which one it is).
 * - payment_attempts: every try to collect money online, kept even when it
 *   fails (Module 12 §8, §41–42; PAY-004).
 * - payment_reconciliation_items: what the reconciliation run found that a
 *   person has to look at (Module 12 §53–55).
 * - payments: the provider and account used, and the reconciliation state.
 *
 * Permissions: payments.provider_config (where the store's money goes —
 * bank details and merchant accounts; Owner only by default, like API
 * keys) and payments.reconcile (resolve what reconciliation found; Owner
 * and Administrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('environment', 16); // sandbox | production
            $table->boolean('is_enabled')->default(false);
            $table->text('credentials'); // encrypted JSON
            $table->json('credential_hints')->nullable(); // masked, safe to show
            $table->unsignedBigInteger('min_amount_minor')->nullable();
            $table->unsignedBigInteger('max_amount_minor')->nullable();
            $table->timestamp('verified_at')->nullable(); // first successful test or real payment in this environment
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure_category', 40)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'provider']);
        });

        Schema::create('platform_payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('provider', 32)->unique();
            $table->string('environment', 16);
            $table->boolean('is_enabled')->default(false);
            $table->text('credentials');
            $table->json('credential_hints')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure_category', 40)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 16); // order | invoice | test
            $table->foreignId('payment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('provider_account_id')->nullable()->constrained('payment_provider_accounts')->nullOnDelete();
            $table->foreignId('platform_account_id')->nullable()->constrained('platform_payment_accounts')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('environment', 16);
            $table->string('reference', 40)->unique(); // ours, sent to the provider (Module 12 §10)
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('payer_hint', 32)->nullable(); // masked wallet number
            $table->string('status', 16); // pending | succeeded | failed | expired | unknown
            $table->string('failure_category', 40)->nullable(); // Module 12 §59
            $table->string('provider_code', 32)->nullable();
            $table->string('provider_message', 255)->nullable();
            $table->string('provider_transaction_reference', 100)->nullable();
            $table->unsignedBigInteger('confirmed_amount_minor')->nullable();
            $table->char('confirmed_currency', 3)->nullable();
            $table->unsignedSmallInteger('check_count')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index(['payment_id', 'created_at']);
            $table->index(['provider', 'provider_transaction_reference']);
        });

        Schema::create('payment_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_attempt_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32); // amount_mismatch | currency_mismatch | paid_after_expiry | status_changed | unresolved_unknown
            $table->json('details')->nullable();
            $table->string('status', 16)->default('open'); // open | resolved
            $table->string('resolution_note', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['payment_attempt_id', 'kind']);
            $table->index(['store_id', 'status']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider', 32)->nullable()->after('method');
            $table->string('reconciliation_status', 16)->nullable()->after('status'); // matched | mismatch | resolved
            // A guest shopper's right to watch and retry this payment: the token is
            // handed out once at checkout; only its hash is kept.
            $table->string('client_token_hash', 64)->nullable()->after('idempotency_key');
            $table->index(['store_id', 'reconciliation_status']);
        });

        DB::table('permissions')->insertOrIgnore([
            ['key' => 'payments.provider_config', 'group' => 'payments', 'description' => 'Set up how the store is paid: bank details, cash on delivery and merchant accounts (Phase B48)'],
            ['key' => 'payments.reconcile', 'group' => 'payments', 'description' => 'Resolve payments that reconciliation flagged (Phase B48)'],
        ]);
        $reconcile = DB::table('permissions')->where('key', 'payments.reconcile')->value('id');
        DB::table('roles')->where('is_system', true)->where('slug', 'administrator')->orderBy('id')
            ->chunkById(500, fn ($roles) => DB::table('permission_role')->insertOrIgnore(
                $roles->map(fn ($role) => ['role_id' => $role->id, 'permission_id' => $reconcile])->all(),
            ));
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', ['payments.provider_config', 'payments.reconcile'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'reconciliation_status']);
            $table->dropColumn(['provider', 'reconciliation_status', 'client_token_hash']);
        });
        Schema::dropIfExists('payment_reconciliation_items');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('platform_payment_accounts');
        Schema::dropIfExists('payment_provider_accounts');
    }
};
