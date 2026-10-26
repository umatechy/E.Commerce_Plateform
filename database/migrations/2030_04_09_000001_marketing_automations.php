<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase P4b — marketing automation (Module 15 §29–44 triggers, §92 Business
 * "Automation"; Module 04 §8 `marketing.advanced`).
 *
 * - `marketing_automations`: one row per store and kind (welcome,
 *   post_purchase, win_back, birthday): on or off, when, and the message.
 *   Off until the store turns it on; starts counting from that moment so
 *   customers from before are not written to at once.
 * - `marketing_automation_sends`: who got which message for what (one key
 *   per customer and occasion), so nobody gets the same one twice.
 *
 * Messages go through the notification pipeline (Module 21) as marketing
 * email: consent, suppression and unsubscribe apply. Store-scoped, purged
 * with the store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->boolean('is_enabled')->default(false);
            $table->unsignedSmallInteger('delay_days')->default(0);
            $table->string('subject', 200);
            $table->text('body');
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'kind'], 'uniq_marketing_automations_store_kind');
        });

        Schema::create('marketing_automation_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('marketing_automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('occasion', 64); // e.g. order:123, 2026 — once per customer and occasion
            $table->foreignId('notification_message_id')->nullable()->constrained('notification_messages')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['marketing_automation_id', 'customer_id', 'occasion'], 'uniq_marketing_automation_sends_once');
            $table->index(['store_id', 'created_at'], 'idx_marketing_automation_sends_store_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_automation_sends');
        Schema::dropIfExists('marketing_automations');
    }
};
