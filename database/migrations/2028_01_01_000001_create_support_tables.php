<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 34 (Phase B26) — support tickets on two desks:
//   store    — a store's shoppers (signed-in or guest) ↔ that store's staff;
//   platform — a store's team ↔ the platform's own support staff.
// Every ticket belongs to a store (the tenant), so a store only ever
// sees its own tickets; the desk decides who answers them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_sequences', function (Blueprint $table) {
            $table->string('key', 32)->primary(); // "store:{id}" or "platform"
            $table->unsignedBigInteger('next_value');
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_support_tickets_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('desk', 16); // SupportDesk
            $table->string('number', 20);
            $table->string('subject', 200);
            $table->string('category', 32); // SupportCategory
            $table->string('priority', 16); // SupportPriority
            $table->string('status', 24); // SupportStatus
            $table->string('channel', 16); // web | email | staff
            $table->string('requester_type', 16); // customer | guest | user
            $table->unsignedBigInteger('requester_id')->nullable();
            $table->string('requester_name', 190);
            $table->string('requester_email', 190);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            // Guests reach their ticket through an emailed link; only a hash is kept.
            $table->char('guest_token_hash', 64)->nullable();
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('sla_breached_at')->nullable();
            $table->timestamp('last_requester_activity_at')->nullable();
            $table->timestamp('last_agent_activity_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedTinyInteger('satisfaction_rating')->nullable();
            $table->string('satisfaction_comment', 1000)->nullable();
            $table->timestamps();

            $table->unique(['desk', 'store_id', 'number'], 'uniq_support_tickets_desk_store_number');
            $table->index(['store_id', 'desk', 'status'], 'idx_support_tickets_store_desk_status');
            $table->index(['desk', 'status', 'first_response_due_at'], 'idx_support_tickets_desk_status_due');
            $table->index(['store_id', 'requester_type', 'requester_id'], 'idx_support_tickets_requester');
            $table->index(['assignee_id', 'status'], 'idx_support_tickets_assignee_status');
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_support_messages_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('author_type', 16); // requester | agent | system
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name', 190);
            $table->text('body');
            // Internal notes are for the answering team only, never shown to the requester.
            $table->boolean('is_internal')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_id', 'id'], 'idx_support_messages_ticket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_ticket_sequences');
    }
};
