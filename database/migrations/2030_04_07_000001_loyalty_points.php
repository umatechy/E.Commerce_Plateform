<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase P2 — loyalty points (Module 10 §45, Module 15 §45, Module 14 §76;
 * Module 04 §8: Premium).
 *
 * - `loyalty_programs`: the store's rules, one row per store. Every rate is
 *   the store's own; nothing is on until the store turns it on.
 * - `loyalty_accounts`: one points balance per customer. Unsigned: the
 *   database refuses a negative balance.
 * - `loyalty_entries`: every change of points, never edited or deleted, with
 *   what it was for and who did it (tenant-scoped and auditable, §45).
 * - `loyalty_order_awards`: what an order earns — pending until the order
 *   qualifies, then earned; refunds take back the same share.
 * - `customers.birth_month` / `birth_day`: for birthday rewards. Only the day
 *   and month, given by the customer, never the year.
 *
 * Points become money only by turning them into store credit, through the
 * store credit ledger (§76: separate from promotion rules, through a
 * controlled adjustment).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'loyalty.view' => ['View the loyalty program and members\' points (Phase P2)', ['administrator', 'manager', 'content-marketing']],
        'loyalty.manage' => ['Set up and run the loyalty program (Phase P2)', ['administrator', 'manager', 'content-marketing']],
        'loyalty.adjust' => ['Give or take a customer\'s loyalty points by hand (Phase P2)', ['administrator']],
    ];

    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->char('currency', 3);
            $table->unsignedInteger('earn_points');              // points given ...
            $table->unsignedBigInteger('earn_spend_minor');      // ... for every this much spent
            $table->unsignedInteger('redeem_points');            // points that turn into ...
            $table->unsignedBigInteger('redeem_value_minor');    // ... this much store credit
            $table->unsignedInteger('min_redeem_points');
            $table->unsignedSmallInteger('hold_days')->default(0); // days after delivery before points can be used
            $table->unsignedSmallInteger('expiry_days')->nullable(); // points lapse after this many days without activity; null = never
            $table->unsignedInteger('birthday_points')->default(0);
            $table->unsignedSmallInteger('repeat_every')->nullable(); // every Nth order earns ...
            $table->unsignedInteger('repeat_points')->default(0);    // ... these bonus points
            $table->json('tiers');                                // [{name, min_points, bonus_percent}]
            $table->timestamp('started_at')->nullable();          // orders placed from here on earn points
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedBigInteger('lifetime_points')->default(0); // earned, less what was taken back; decides the tier
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'customer_id'], 'uniq_loyalty_accounts_customer');
        });

        Schema::create('loyalty_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_loyalty_entries_public_id');
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('loyalty_accounts')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('type', 24);                          // LoyaltyEntryType
            $table->bigInteger('points');                        // + earned, − used or taken back
            $table->unsignedBigInteger('balance_after');
            $table->string('reference_type', 24)->nullable();    // order | store_credit
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['store_id', 'idempotency_key'], 'uniq_loyalty_entries_store_idempotency');
            $table->index(['store_id', 'customer_id', 'id'], 'idx_loyalty_entries_store_customer');
            $table->index(['store_id', 'type', 'created_at'], 'idx_loyalty_entries_store_type');
        });

        Schema::create('loyalty_order_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('status', 12);                        // pending | earned | cancelled
            $table->unsignedBigInteger('base_minor');            // what the points are for
            $table->unsignedBigInteger('points');                // what the order earns in full
            $table->unsignedBigInteger('reversed_points')->default(0);
            $table->unsignedBigInteger('bonus_points')->default(0); // repeat-purchase bonus given with it
            $table->json('snapshot');                            // the rates and tier it was counted with
            $table->timestamp('hold_until')->nullable();
            $table->timestamp('earned_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'status'], 'idx_loyalty_awards_store_status');
            $table->index(['store_id', 'customer_id'], 'idx_loyalty_awards_store_customer');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedTinyInteger('birth_month')->nullable();
            $table->unsignedTinyInteger('birth_day')->nullable();
        });

        foreach (self::PERMISSIONS as $key => [$description, $roles]) {
            DB::table('permissions')->insertOrIgnore([['key' => $key, 'group' => 'marketing', 'description' => $description]]);
            $permission = DB::table('permissions')->where('key', $key)->value('id');
            DB::table('roles')->where('is_system', true)->whereIn('slug', $roles)->orderBy('id')
                ->chunkById(500, fn ($rows) => DB::table('permission_role')->insertOrIgnore(
                    $rows->map(fn ($role) => ['role_id' => $role->id, 'permission_id' => $permission])->all(),
                ));
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['birth_month', 'birth_day']));
        Schema::dropIfExists('loyalty_order_awards');
        Schema::dropIfExists('loyalty_entries');
        Schema::dropIfExists('loyalty_accounts');
        Schema::dropIfExists('loyalty_programs');
    }
};
