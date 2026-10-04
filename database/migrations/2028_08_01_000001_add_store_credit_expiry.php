<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B35 — Module 09 §52 "expiry-aware where applicable".
 *
 * Each credit that comes in is a lot with its own expiry (or none). What is
 * spent is taken from the lot that expires first, so the ledger stays
 * append-only while the lots say what is still left of each credit. When the
 * store turns expiry on (setting `store_credit.expires`, off by default — the
 * policy is the owner's), credit given from then on expires after
 * `store_credit.expiry_days`; what lapses is written to the ledger as an
 * `expired` entry by the daily `store-credit:expire` command.
 *
 * Balances that exist already become one lot each, without expiry: turning
 * expiry on never shortens credit a customer already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_credit_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('store_credit_accounts')->restrictOnDelete();
            $table->foreignId('entry_id')->nullable()->constrained('store_credit_entries')->restrictOnDelete(); // the credit it came from
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('remaining_minor');
            $table->timestamp('expires_at')->nullable(); // null = never
            $table->timestamps();

            $table->index(['account_id', 'remaining_minor'], 'idx_store_credit_lots_account_remaining');
            $table->index(['store_id', 'expires_at', 'remaining_minor'], 'idx_store_credit_lots_store_expiry');
        });

        $now = now();
        foreach (DB::table('store_credit_accounts')->where('balance_minor', '>', 0)->orderBy('id')->get() as $account) {
            DB::table('store_credit_lots')->insert([
                'store_id' => $account->store_id, 'account_id' => $account->id, 'entry_id' => null,
                'amount_minor' => $account->balance_minor, 'remaining_minor' => $account->balance_minor,
                'expires_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_credit_lots');
    }
};
