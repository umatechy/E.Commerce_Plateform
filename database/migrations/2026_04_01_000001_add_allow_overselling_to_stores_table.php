<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 08 §24 "Overselling Protection" — a per-store setting, additive
// column, defaults to the SAFE choice (false = overselling disabled,
// i.e. stock cannot go negative) so existing/new stores are protected
// by default rather than needing to opt in to safety.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('allow_overselling')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('allow_overselling');
        });
    }
};
