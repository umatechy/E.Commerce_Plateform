<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 15 §13/§16 "Marketing Consent / Channel Preference". The
// ONLY consent channel B10 implements — see
// docs/development/b10-inspection-findings.md "Architectural Decision
// — Minimal Consent and Segment Foundation". Defaults to false:
// opt-IN required, never opt-out-by-default.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('marketing_email_opt_in')->default(false)->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('marketing_email_opt_in');
        });
    }
};
