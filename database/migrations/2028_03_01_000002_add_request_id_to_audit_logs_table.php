<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase B29 (gap G2) — the request / correlation ID of the request that
// wrote an audit entry (SRS API-011; Module 32 §63.3). Null for entries
// written before this phase and for console work. It is part of the
// entry's hash whenever it is present (AuditHasher).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('request_id', 64)->nullable()->after('user_agent');
            $table->index('request_id', 'idx_audit_logs_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('idx_audit_logs_request_id');
            $table->dropColumn('request_id');
        });
    }
};
