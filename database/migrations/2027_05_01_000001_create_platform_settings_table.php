<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 33 §3.1/§25 "Platform Scope / Database Architecture" —
// Architectural Decision (see docs/development/b17-inspection-findings.md):
// a SEPARATE table from store_settings, never a nullable store_id
// column on one shared table, avoiding MySQL's multi-NULL-is-distinct
// unique-index ambiguity entirely. `value` is always JSON-encoded
// regardless of the underlying SettingDefinition type (uniform
// storage) — a `secret`-typed value is Laravel-Crypt-encrypted BEFORE
// being JSON-encoded here, never plaintext.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
