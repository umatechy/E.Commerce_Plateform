<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision (2026-10-17): a package is bought with a one-time price that
 * includes its first year, then renewed every year at the yearly price. The
 * first billed period of a subscription is charged first_period_amount_minor
 * when set; every later period is charged amount_minor. Both are set (and
 * changed) by Umar Techy in Super Admin → Platform billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('first_period_amount_minor')->nullable()->after('amount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('package_prices', function (Blueprint $table) {
            $table->dropColumn('first_period_amount_minor');
        });
    }
};
