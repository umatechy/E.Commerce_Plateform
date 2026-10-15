<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B55 (gap G16; Module 25 §51–54, §68–69, §79) — the store's
 * installable app: push subscriptions (the foundation; delivery belongs to
 * Module 21) and daily app counters without any device identifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pwa_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->char('endpoint_hash', 64);          // SHA-256 of the endpoint: finds a subscription without reading it
            $table->text('endpoint');                    // encrypted: the endpoint is a capability URL (Module 25 §52, §54)
            $table->text('public_key');                  // encrypted p256dh
            $table->text('auth_secret');                 // encrypted auth
            $table->string('locale', 8)->default('en');
            $table->string('browser', 40)->nullable();   // "Chrome on Android": no device identifier (§69)
            $table->string('status', 10)->default('active'); // active | revoked
            $table->timestamps();
            $table->timestamp('revoked_at')->nullable();
            $table->unique(['store_id', 'endpoint_hash']);
            $table->index(['store_id', 'status']);
        });

        // Module 25 §68: install, launch from the home screen, offline use, push opt-in — counted per day.
        Schema::create('pwa_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('event', 24);
            $table->unsignedInteger('count')->default(0);
            $table->unique(['store_id', 'day', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pwa_daily_stats');
        Schema::dropIfExists('pwa_push_subscriptions');
    }
};
