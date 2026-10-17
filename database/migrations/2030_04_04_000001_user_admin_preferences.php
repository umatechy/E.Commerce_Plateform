<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UI-20: a user's admin preferences — the theme colour (indigo, emerald,
 * slate) and the admin language (en, ur) — kept with the account, so they
 * follow the user to every device. Only these keys are written
 * (AccountPreferencesController validates them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('admin_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_preferences');
        });
    }
};
