<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 22 §43-44 "Report Exports / Export Security". A tenant-
// scoped, idempotent record of one export request — the generated
// file lives under storage/app/private (never a predictable public
// URL, Non-Negotiable) and is only ever reachable via a
// Laravel-signed, time-limited download route (see
// ReportExportController).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_report_exports_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_type', 24); // ReportType
            $table->json('filters'); // the exact validated filter set used — reproducibility/audit
            $table->string('status', 16)->default('pending'); // ReportExportStatus
            $table->string('file_path')->nullable(); // storage/app/private path — never exposed directly to clients
            $table->unsignedInteger('row_count')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('idempotency_key');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'idempotency_key'], 'uniq_report_exports_store_id_idempotency_key');
            $table->index(['store_id', 'status'], 'idx_report_exports_store_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
