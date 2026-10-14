<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B50 (gap G10; Module 21 §8–10, §18–19, §31–37, §40–41, §46, §48,
 * §52; owner decision 3: WhatsApp through Meta's WhatsApp Business Cloud
 * API, SMS behind an abstraction — Twilio or a Pakistani provider after
 * sender-ID, delivery and pricing checks).
 *
 * - messaging_accounts: a store's own WhatsApp Business number or SMS
 *   account (store_id), or Umar Techy's own (store_id null — critical alerts).
 *   Credentials encrypted; `webhook_token` names the account in its webhook
 *   address (and is Meta's verify token).
 * - messaging_templates: which provider-approved WhatsApp template carries
 *   which event, and its approval state (§37: tracked separately from the
 *   platform's own templates).
 * - messaging_webhook_events: each provider delivery report applied once.
 * - customers.whatsapp_opt_in / orders.whatsapp_opt_in: WhatsApp updates
 *   only with the customer's yes (Meta policy; Module 21 §9).
 * - notification_messages.read_at already exists (in-app); delivery
 *   attempts are found by the provider's message id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('channel', 16); // sms | whatsapp
            $table->string('provider', 32); // meta_whatsapp | twilio | test_messenger
            $table->string('environment', 16);
            $table->boolean('is_enabled')->default(false);
            $table->text('credentials');
            $table->json('credential_hints')->nullable();
            $table->string('webhook_token', 64)->unique();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure_message', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'channel']);
        });

        Schema::create('messaging_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_key', 64);
            $table->string('template_name', 120);
            $table->string('language_code', 16);
            $table->string('status', 16)->default('submitted'); // submitted | approved | rejected | paused
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'event_key']);
        });

        Schema::create('messaging_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('messaging_account_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 80);
            $table->string('kind', 16); // status | inbound | other
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['messaging_account_id', 'event_key']);
        });

        Schema::table('notification_delivery_attempts', function (Blueprint $table) {
            $table->index(['provider', 'provider_message_id'], 'idx_notification_attempts_provider_message');
        });
        Schema::table('notification_messages', function (Blueprint $table) {
            // The values of an approved WhatsApp template, in its order ({{1}}, {{2}} …).
            $table->json('provider_parameters')->nullable()->after('sealed_body');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('whatsapp_opt_in')->default(false)->after('marketing_email_opt_in');
            $table->timestamp('whatsapp_opt_in_at')->nullable()->after('whatsapp_opt_in');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('whatsapp_opt_in')->default(false)->after('guest_phone');
        });

        // Module 21 §59: provider credentials need an elevated permission (Owner and Administrator).
        \Illuminate\Support\Facades\DB::table('permissions')->insertOrIgnore([
            ['key' => 'messaging.providers', 'group' => 'notifications', 'description' => 'Connect the store\'s SMS and WhatsApp accounts (Phase B50)'],
        ]);
        $permission = \Illuminate\Support\Facades\DB::table('permissions')->where('key', 'messaging.providers')->value('id');
        \Illuminate\Support\Facades\DB::table('roles')->where('is_system', true)->where('slug', 'administrator')->orderBy('id')
            ->chunkById(500, fn ($roles) => \Illuminate\Support\Facades\DB::table('permission_role')->insertOrIgnore(
                $roles->map(fn ($role) => ['role_id' => $role->id, 'permission_id' => $permission])->all(),
            ));
    }

    public function down(): void
    {
        $permission = \Illuminate\Support\Facades\DB::table('permissions')->where('key', 'messaging.providers')->value('id');
        \Illuminate\Support\Facades\DB::table('permission_role')->where('permission_id', $permission)->delete();
        \Illuminate\Support\Facades\DB::table('permissions')->where('key', 'messaging.providers')->delete();
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('whatsapp_opt_in'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['whatsapp_opt_in', 'whatsapp_opt_in_at']));
        Schema::table('notification_messages', fn (Blueprint $table) => $table->dropColumn('provider_parameters'));
        Schema::table('notification_delivery_attempts', function (Blueprint $table) {
            $table->dropIndex('idx_notification_attempts_provider_message');
        });
        Schema::dropIfExists('messaging_webhook_events');
        Schema::dropIfExists('messaging_templates');
        Schema::dropIfExists('messaging_accounts');
    }
};
