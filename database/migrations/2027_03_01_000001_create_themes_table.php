<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 17 §7/§15 "Theme Registry / Theme Architecture" — a
// PLATFORM-level catalog (NOT tenant-scoped, no BelongsToTenant) —
// mirrors Package's own platform-catalog precedent (Phase B2). Only
// one row is seeded in B15 (see inspection findings "Scope Decision")
// — no marketplace, no competing themes yet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('version', 16);
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
