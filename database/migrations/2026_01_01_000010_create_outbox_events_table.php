<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-004 transactional outbox. High-volume table — lean row width,
// purpose-built indexes only (ADR-003 "High-volume tables").
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            // Nullable: platform-scope changes (a platform setting, a
            // platform backup) have no owning store. Every tenant event
            // still carries its store_id (RecordsOutboxEvents).
            $table->foreignId('store_id')->nullable()->constrained('stores')->restrictOnDelete();
            $table->string('event_type', 128);
            $table->json('payload');
            $table->string('idempotency_key')->unique('uniq_outbox_events_idempotency_key');
            $table->string('status', 16)->default('pending'); // pending|processing|published|failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['status', 'available_at'], 'idx_outbox_events_status_available_at');
            $table->index(['store_id', 'event_type'], 'idx_outbox_events_store_id_event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
