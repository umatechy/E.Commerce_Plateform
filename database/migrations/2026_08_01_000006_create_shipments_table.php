<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 13 §47-48 "Shipment Entity / Shipment vs Order". NO
// unique(order_id) constraint — deliberately, unlike Payment's
// unique(store_id, order_id) — Module 13 §48/Final Rule #16 explicitly
// allows "One Order may contain ... Multiple Shipments."
// label_url reserved for a future real carrier integration (Module 13
// §55-56) — nullable, unpopulated in B8 (see inspection findings).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_shipments_public_id');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->nullOnDelete();
            $table->foreignId('pickup_location_id')->nullable()->constrained('pickup_locations')->nullOnDelete();
            $table->string('carrier', 32); // e.g. "store_pickup", "local_delivery", "mock_courier"
            $table->string('status', 24)->default('draft'); // ShipmentStatus
            $table->string('tracking_number')->nullable();
            $table->string('label_url')->nullable(); // reserved, unpopulated in B8 — see class docblock
            $table->bigInteger('shipping_cost_minor')->default(0);
            $table->char('currency', 3);
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('idempotency_key');
            $table->timestamps();

            $table->unique(['store_id', 'tracking_number'], 'uniq_shipments_store_id_tracking_number');
            $table->unique(['store_id', 'idempotency_key'], 'uniq_shipments_store_id_idempotency_key');
            $table->index(['store_id', 'order_id'], 'idx_shipments_store_id_order_id');
            $table->index(['store_id', 'status'], 'idx_shipments_store_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
