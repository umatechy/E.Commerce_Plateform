<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 02 §19/§37 — who last changed a membership's status and when
 * (suspended, reactivated, removed). The full history stays in the audit
 * log; these columns let the team page show it without reading the log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_user', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable()->after('status');
            $table->foreignId('status_changed_by_user_id')->nullable()->after('status_changed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('store_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_changed_by_user_id');
            $table->dropColumn('status_changed_at');
        });
    }
};
