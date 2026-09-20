<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 21 §10 "Suppression System" — a stronger, provider-driven
// block (bounce/explicit unsubscribe) than the simple
// Customer.marketing_email_opt_in preference toggle (Phase B10, reused
// unchanged). Only ever consulted for MARKETING messages (Module 21
// §10/Data Integrity Rule #6 — mandatory transactional/system/
// security communication is never suppressed).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('channel', 16); // NotificationChannel
            $table->string('destination'); // normalized email/phone
            $table->string('reason', 32); // e.g. "unsubscribed", "bounced", "complained"
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['store_id', 'channel', 'destination'], 'uniq_notification_suppressions_store_id_channel_destination');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_suppressions');
    }
};
