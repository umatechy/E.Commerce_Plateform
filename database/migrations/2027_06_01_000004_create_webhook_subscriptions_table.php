<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 31 §36-37 "Webhooks / Webhook Signing". `signing_secret` is
// shown once at creation (mirrors ApiKey's own "generate once, show
// once" discipline) — never re-displayed after creation.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('developer_application_id')->constrained('developer_applications')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('url');
            $table->string('signing_secret', 64);
            $table->json('subscribed_events'); // list of EXISTING outbox event_type strings only
            $table->string('status', 16)->default('active'); // WebhookSubscriptionStatus
            $table->timestamps();

            $table->index(['store_id', 'status'], 'idx_webhook_subscriptions_store_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
