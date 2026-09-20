<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 21 §12-13/§44 Rule #3 "Published templates are immutable" —
// enforced at the application layer in NotificationTemplateService
// (a database CHECK constraint cannot express "cannot be updated once
// a flag is true"); to change a published template's content, a NEW
// row is created instead. `key` lets NotificationEventRouter look up
// "the template for event X, channel Y" deterministically.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('key'); // e.g. "order.created", "marketing.recipient_queued"
            $table->string('channel', 16); // NotificationChannel
            $table->string('locale', 8)->default('en');
            $table->string('subject')->nullable(); // null for channels with no subject concept (SMS/in-app)
            $table->text('body');
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->unique(['store_id', 'key', 'channel', 'locale'], 'uniq_notification_templates_store_id_key_channel_locale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
