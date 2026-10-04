<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 09 §52 "Store Credit Foundation", Module 12 §13 (Phase B34).
 *
 * Store credit is money the store owes a customer, kept as a ledger:
 * - `store_credit_accounts`: one balance per customer and currency. It
 *   is unsigned: the database itself refuses a negative balance.
 * - `store_credit_entries`: every change, never edited or deleted, with
 *   what it was for and who did it (tenant-scoped, auditable).
 * - `orders.store_credit_minor`: the part of an order paid with credit.
 *   The order's payment is for the rest (Module 12 §13: order total −
 *   eligible store credit = remaining payable amount).
 * - `return_requests.refund_method` / `refunded_credit_minor`: a refund
 *   paid back as credit.
 *
 * No expiry: a balance does not lapse (§52 "expiry-aware where
 * applicable" — an expiry policy would be an owner decision).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_credit_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->char('currency', 3);
            $table->unsignedBigInteger('balance_minor')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'customer_id', 'currency'], 'uniq_store_credit_accounts_customer_currency');
        });

        Schema::create('store_credit_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_store_credit_entries_public_id');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('store_credit_accounts')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('type', 24); // StoreCreditEntryType
            $table->bigInteger('amount_minor'); // + added, − used
            $table->unsignedBigInteger('balance_after_minor');
            $table->char('currency', 3);
            $table->string('reference_type', 24)->nullable(); // order | return
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['store_id', 'idempotency_key'], 'uniq_store_credit_entries_store_idempotency');
            $table->index(['store_id', 'customer_id', 'id'], 'idx_store_credit_entries_store_customer');
            $table->index(['store_id', 'reference_type', 'reference_id'], 'idx_store_credit_entries_reference');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('store_credit_minor')->default(0)->after('grand_total_minor');
        });

        Schema::table('return_requests', function (Blueprint $table) {
            $table->string('refund_method', 16)->default('payment')->after('refund_total_minor'); // payment | store_credit
            $table->bigInteger('refunded_credit_minor')->default(0)->after('refunded_minor');
        });

        // Giving or taking credit by hand is creating money: its own permission, Administrator and Owner.
        if (! DB::table('permissions')->where('key', 'store_credit.manage')->exists()) {
            DB::table('permissions')->insert(['key' => 'store_credit.manage', 'group' => 'customers', 'description' => 'Give or take back store credit by hand (Module 09 §52 — Phase B34)']);
        }
        $permissionId = DB::table('permissions')->where('key', 'store_credit.manage')->value('id');
        foreach (DB::table('roles')->where('slug', 'administrator')->where('is_system', true)->pluck('id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropColumn(['refund_method', 'refunded_credit_minor']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('store_credit_minor');
        });
        Schema::dropIfExists('store_credit_entries');
        Schema::dropIfExists('store_credit_accounts');
        DB::table('permissions')->where('key', 'store_credit.manage')->delete();
    }
};
