<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 31 §36/§53 — append-only delivery ledger, same 2-in-1
// collapse pattern (delivery + attempt) as every ledger since Phase
// B7's PaymentTransaction. Uniqueness is on
// (subscription, idempotency_key, attempt_number) — NOT on
// (subscription, idempotency_key) alone, which would have wrongly
// prevented recording more than one retry attempt for the same event
// (see docs/development/b18-inspection-findings.md). A prior
// successful delivery is checked at the APPLICATION level (query for
// an existing result='succeeded' row for that idempotency_key) before
// ever dispatching another attempt — the same pattern this codebase
// has used for every other append-only ledger since B7.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('webhook_subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('idempotency_key');
            $table->unsignedInteger('attempt_number');
            $table->string('result', 16); // 'succeeded' | 'failed'
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->unique(['webhook_subscription_id', 'idempotency_key', 'attempt_number'], 'uniq_webhook_delivery_attempts_subscription_key_attempt');
            $table->index(['webhook_subscription_id', 'idempotency_key'], 'idx_webhook_delivery_attempts_subscription_id_idempotency_key');
            $table->index(['store_id', 'webhook_subscription_id'], 'idx_webhook_delivery_attempts_store_id_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_delivery_attempts');
    }
};
