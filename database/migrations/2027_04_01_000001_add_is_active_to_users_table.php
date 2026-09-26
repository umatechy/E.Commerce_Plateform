<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 30 §12 "User & Staff Oversight" — a platform-wide account
// lock, distinct from per-store role-membership status (store_user.status,
// unchanged) and distinct from Customer/Staff guard boundaries (never
// touched). Default true — existing accounts remain unaffected.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('platform_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
