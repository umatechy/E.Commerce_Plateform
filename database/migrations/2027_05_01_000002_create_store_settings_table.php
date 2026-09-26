<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 33 §3.3-3.4/§25 — one row per (store, key). Strong uniqueness
// via a real store_id column (never nullable) — see
// docs/development/b17-inspection-findings.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('key');
            $table->json('value');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'key'], 'uniq_store_settings_store_id_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_settings');
    }
};
