<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

// Module 12 §31/§34 "Provider Configuration / Store-Specific Provider
// Accounts". Minimal per-store secret for the Mock Redirect Gateway's
// webhook signature verification (Module 12 §30). NEVER exposed via
// any API response — see PaymentGatewayConfigResource (there isn't
// one; this column is read only by PaymentService/gateway internals).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('payment_webhook_secret', 64)->nullable()->after('allow_overselling');
        });

        // Backfill existing stores with a REAL RANDOM secret each —
        // done row-by-row deliberately: a single
        // ->update(['payment_webhook_secret' => Str::random(64)])
        // would evaluate Str::random() ONCE in PHP and assign the SAME
        // value to every existing store, which would be a severe
        // security defect (any one store's secret would then verify
        // signatures for every other store too). Caught during
        // migration review before being left in the codebase.
        DB::table('stores')->whereNull('payment_webhook_secret')->orderBy('id')
            ->pluck('id')
            ->each(function (int $storeId) {
                DB::table('stores')->where('id', $storeId)->update([
                    'payment_webhook_secret' => Str::random(64),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('payment_webhook_secret');
        });
    }
};
