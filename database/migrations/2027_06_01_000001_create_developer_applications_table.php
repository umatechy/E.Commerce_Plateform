<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 31 §7/§39 "Developer Registration / Applications" —
// tenant-owned (each Store creates its own applications). Every
// ApiKey references one Application; an Application's own store_id is
// the SINGLE source of tenant authority for every key issued under it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('developer_applications', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_developer_applications_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 16)->default('active'); // ApplicationStatus
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'status'], 'idx_developer_applications_store_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('developer_applications');
    }
};
