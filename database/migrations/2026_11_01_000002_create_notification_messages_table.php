<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 21 §43 "Message / MessageRecipient / MessageContent" (B11's
// documented 3-in-1 collapse — see inspection findings). `destination`
// is a snapshot of the actual email/phone/user-id AT SEND TIME
// (Module 21 §8 normalization) — never re-derived from a possibly-
// since-changed Customer record, same snapshot philosophy as OrderItem.
// `read_at` serves the in_app channel's read/unread state (§21) —
// null for every other channel, avoiding a duplicate InAppNotification
// table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_notification_messages_public_id');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('message_type', 24); // NotificationMessageType
            $table->string('channel', 16); // NotificationChannel
            $table->string('recipient_type', 16); // RecipientType
            $table->unsignedBigInteger('recipient_id')->nullable(); // null for a guest recipient (no Customer row exists — Phase B5's guest checkout) — `destination` alone is authoritative for where to send
            $table->string('destination'); // email/phone snapshot — see docblock
            $table->foreignId('notification_template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('status', 16)->default('created'); // NotificationStatus
            $table->string('source_event_type')->nullable(); // the outbox event_type that triggered this, e.g. "order.created" — traceability, not a foreign key (outbox rows are not permanently retained)
            $table->string('idempotency_key');
            $table->timestamp('read_at')->nullable(); // in_app channel only
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'idempotency_key'], 'uniq_notification_messages_store_id_idempotency_key');
            $table->index(['store_id', 'recipient_type', 'recipient_id', 'created_at'], 'idx_notification_messages_store_id_recipient_created_at');
            $table->index(['store_id', 'status'], 'idx_notification_messages_store_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_messages');
    }
};
