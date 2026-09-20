<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 21 §28 "Delivery Attempts" — append-only ledger, same 2-in-1
// collapse (Delivery + DeliveryAttempt + DeliveryProviderReference) as
// Phase B7's PaymentTransaction / B8's ShipmentTrackingEvent. Never
// stores full message content (§28: "sensitive message content should
// not be stored unnecessarily") — only the provider-facing metadata.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('notification_message_id')->constrained('notification_messages')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('provider', 32); // e.g. "smtp", "in_app", "stub_sms"
            $table->string('provider_message_id')->nullable();
            $table->string('result', 16); // DeliveryAttemptResult
            $table->string('failure_code')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['store_id', 'notification_message_id'], 'idx_notification_delivery_attempts_store_id_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_delivery_attempts');
    }
};
