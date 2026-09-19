<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 13 §53 "Carrier Webhooks" — mirrors Phase B7's
// payment_webhook_events table exactly (same dedup/trust rationale:
// store_id is populated only AFTER the referenced Shipment is resolved
// and the signature verified, never assumed from the payload up front).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('external_event_id');
            $table->string('status', 16)->default('received');
            $table->json('payload');
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id'], 'uniq_shipment_webhook_events_provider_external_event_id');
            $table->index(['store_id', 'shipment_id'], 'idx_shipment_webhook_events_store_id_shipment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_webhook_events');
    }
};
