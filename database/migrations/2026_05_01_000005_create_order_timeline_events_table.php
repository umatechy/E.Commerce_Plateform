<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 09 §30 "Order Timeline" + §75 "Audit Logging" — B5 deliberately
// implements ONE append-only table serving both purposes (see
// docs/development/b5-inspection-findings.md "Scope Decision" for why:
// Module 32's platform-wide audit log does not exist yet). Never
// updated or deleted by application code — same append-only convention
// as B4's stock_movements.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('event_type', 32); // e.g. "created", "status_changed", "cancelled"
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_id', 'order_id', 'created_at'], 'idx_order_timeline_events_store_id_order_id_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_timeline_events');
    }
};
