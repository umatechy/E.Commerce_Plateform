<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 12 §26-29 "Webhooks / Webhook Idempotency / Replay
// Protection". external_event_id + provider together form the
// dedup key (Module 12 §28: "Store provider event IDs where
// available") — a store_id is deliberately NOT part of this unique
// constraint's lookup key at receipt time, because the whole point of
// this table is to resolve store/tenant identity FROM the verified
// payment reference inside the payload, not to assume it up front
// (Step 10: "webhook payload must NOT be trusted merely because it
// contains tenant_id"). store_id here is recorded AFTER verification,
// for audit/query purposes only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete(); // nullable: an event that fails to resolve to any known payment still gets logged, unattributed
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('provider', 32); // e.g. "mock_redirect"
            $table->string('external_event_id'); // provider's own event/delivery ID
            $table->string('status', 16)->default('received'); // WebhookEventStatus
            $table->json('payload'); // raw, as received — see PaymentService for redaction-on-read discipline
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id'], 'uniq_payment_webhook_events_provider_external_event_id');
            $table->index(['store_id', 'payment_id'], 'idx_payment_webhook_events_store_id_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
