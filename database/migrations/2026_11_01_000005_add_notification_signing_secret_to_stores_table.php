<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Module 21 §85 "Link Security" — signs unsubscribe links so a
// customer can opt out with no login required, while an attacker
// cannot forge a signature to unsubscribe someone else. A THIRD,
// separate secret from payment_webhook_secret (B7) and
// shipment_webhook_secret (B8) — different purpose, different
// rotation lifecycle; per-row backfilled from the start (B8 already
// learned this lesson from B7's bulk-UPDATE mistake — not repeated
// here either).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('notification_signing_secret', 64)->nullable()->after('shipment_webhook_secret');
        });

        DB::table('stores')->whereNull('notification_signing_secret')->orderBy('id')
            ->pluck('id')
            ->each(function (int $storeId) {
                DB::table('stores')->where('id', $storeId)->update([
                    'notification_signing_secret' => Str::random(64),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('notification_signing_secret');
        });
    }
};
