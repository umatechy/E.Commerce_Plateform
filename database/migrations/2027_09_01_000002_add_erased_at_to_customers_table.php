<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 32 (Phase B22) — marks a customer whose personal data was erased
// on request. The row itself stays: orders keep referencing it for
// financial retention, with every personal field anonymized.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('erased_at')->nullable()->after('marketing_email_opt_in');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('erased_at');
        });
    }
};
