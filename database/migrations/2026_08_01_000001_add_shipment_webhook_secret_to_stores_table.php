<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Module 13 §53/§88 "Carrier Webhooks / Carrier Secret Management" — a
// SEPARATE secret from Phase B7's payment_webhook_secret (different
// external parties, different rotation lifecycle; conflating them would
// mean rotating one secret for an unrelated reason invalidates the
// other's signatures too). Backfilled per-row deliberately — see
// docs/security/b7-security-review.md's documented fix for why a
// single bulk UPDATE with Str::random() would be a severe defect here
// too; the same mistake is not repeated.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('shipment_webhook_secret', 64)->nullable()->after('payment_webhook_secret');
        });

        DB::table('stores')->whereNull('shipment_webhook_secret')->orderBy('id')
            ->pluck('id')
            ->each(function (int $storeId) {
                DB::table('stores')->where('id', $storeId)->update([
                    'shipment_webhook_secret' => Str::random(64),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('shipment_webhook_secret');
        });
    }
};
