<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive-only migration (ADR-003 / this milestone's "Database Safety"
// rules: no destructive change). Adds Module 04 §10/§13 concepts
// (hard/soft enforcement, usage period, explicit "unlimited" flag) to
// the existing package_entitlements table without touching any existing
// column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_entitlements', function (Blueprint $table) {
            $table->string('enforcement', 8)->nullable()->after('type'); // hard|soft — usage_limit rows only
            $table->string('period', 16)->nullable()->after('enforcement'); // persistent|monthly|daily|one_time|concurrent
            $table->boolean('is_unlimited')->default(false)->after('limit_value');
        });
    }

    public function down(): void
    {
        Schema::table('package_entitlements', function (Blueprint $table) {
            $table->dropColumn(['enforcement', 'period', 'is_unlimited']);
        });
    }
};
