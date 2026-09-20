<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 15 §33-35 "Abandoned Cart Recovery / Rules". Marks a cart as
// already having triggered abandoned-cart detection — prevents the
// detection job from re-emitting the same event on every scheduled
// run (idempotency at the DATA level, not just the outbox event's own
// idempotency key, since this also needs to survive across separate
// job invocations cheaply without querying the whole outbox table).
// Deliberately SEPARATE from Cart.status/expires_at (Phase B6) —
// this milestone's own Step 9: "do not confuse cart expiration with
// marketing abandonment."
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->timestamp('abandoned_marketing_notified_at')->nullable()->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('abandoned_marketing_notified_at');
        });
    }
};
