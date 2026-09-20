<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 15 §15/§54 "Campaign Recipients / Idempotency" — the durable
// record that PREVENTS a customer from being queued twice for the
// same campaign, enforced by the unique constraint below (not merely
// application-level checking). No customer PII is copied here beyond
// the foreign key (Module 15 Step 15: "avoid storing unnecessary
// copies of customer PII").
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('status', 24); // CampaignRecipientStatus
            $table->timestamp('queued_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'customer_id'], 'uniq_campaign_recipients_campaign_id_customer_id');
            $table->index(['store_id', 'customer_id', 'created_at'], 'idx_campaign_recipients_store_id_customer_id_created_at'); // frequency-cooldown lookup
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};
