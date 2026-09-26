<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 17 §16/§19 "Theme Lifecycle / Theme Preview" —
// Architectural Decision: one row per store, `draft_config` (mutable,
// edited freely) vs `published_config` (only ever replaced atomically
// by the publish operation) — see
// docs/development/b15-inspection-findings.md. `custom_css` is
// sanitized (CustomCssSanitizer) before storage and gated by the
// theme.custom_css entitlement — never rendered raw from client input.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_themes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->foreignId('theme_id')->constrained('themes')->restrictOnDelete();
            $table->json('draft_config');
            $table->json('published_config')->nullable();
            $table->text('custom_css')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_themes');
    }
};
