<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 15 §5-9. channel is fixed to 'email' in B10 (see inspection
// findings) but stored as a column, not hardcoded in code, so adding a
// real second channel later is a data-level change, not a schema
// migration. scheduled_at/completed_at are evaluated in UTC server
// time (documented simplification — no Store.timezone column exists).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_campaigns_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->string('objective', 32); // CampaignObjective
            $table->string('channel', 16)->default('email');
            $table->string('status', 16)->default('draft'); // CampaignStatus
            $table->string('audience_type', 16); // CampaignAudienceType
            $table->foreignId('marketing_segment_id')->nullable()->constrained('marketing_segments')->nullOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('idempotency_key')->nullable(); // set once execution is dispatched — see CampaignService::activate()
            $table->timestamps();

            $table->unique(['store_id', 'idempotency_key'], 'uniq_campaigns_store_id_idempotency_key');
            $table->index(['store_id', 'status'], 'idx_campaigns_store_id_status');
            $table->index(['store_id', 'scheduled_at'], 'idx_campaigns_store_id_scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
