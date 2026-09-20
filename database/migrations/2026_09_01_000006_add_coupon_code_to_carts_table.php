<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 14 §27 "Coupon Removal" / Step 10 "Cart Integration" — the
// cart remembers which coupon the customer applied so it survives
// across requests (add item, view cart, remove coupon) until
// Checkout re-validates it authoritatively. Storing the RAW code
// (not yet normalized) — CartService always normalizes at lookup
// time, exactly like the Coupon model itself.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->string('coupon_code')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('coupon_code');
        });
    }
};
