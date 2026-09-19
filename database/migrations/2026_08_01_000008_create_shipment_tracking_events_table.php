<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 13 §51-52 "Tracking Events / Tracking History" — append-only
// (never overwritten, per §52's explicit rule), same convention as
// every other ledger table in this codebase (StockMovement,
// OrderTimelineEvent, PaymentTransaction).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->string('status', 24); // ShipmentStatus at the time of this event
            $table->string('carrier_event_code')->nullable();
            $table->string('description')->nullable();
            $table->string('location')->nullable();
            $table->string('source', 16); // "webhook" | "manual" | "system"
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_id', 'shipment_id', 'occurred_at'], 'idx_shipment_tracking_events_store_id_shipment_id_occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_tracking_events');
    }
};
