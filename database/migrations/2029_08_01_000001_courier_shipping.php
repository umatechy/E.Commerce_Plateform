<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B49 (gap G9, shipping side; Module 13 §42–56, §59, §66–72, §74–75,
 * §87–88, §97–98; owner decision 2: the courier is chosen from real API and
 * sandbox availability, nothing is hard-coded before that).
 *
 * - courier_accounts: a store's own account with a courier, encrypted
 *   credentials (Module 13 §44–46, §88), like B48's merchant accounts.
 * - shipments: who carries it (a connected courier, or "another courier"
 *   booked outside with its name and number), parcel weight and pieces,
 *   cash on delivery to collect and its remittance, the booking with the
 *   courier, the label file (private), cancel / back-in-stock, return
 *   shipments (direction), and tracking polling with back-off.
 * - shipment_tracking_events.event_key: a courier event is recorded once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('environment', 16); // sandbox | production
            $table->boolean('is_enabled')->default(false);
            $table->text('credentials'); // encrypted JSON
            $table->json('credential_hints')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure_message', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'provider']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('direction', 8)->default('outbound')->after('carrier'); // outbound | return
            $table->foreignId('return_request_id')->nullable()->after('order_id')->constrained('return_requests')->nullOnDelete();
            $table->foreignId('courier_account_id')->nullable()->after('carrier')->constrained('courier_accounts')->nullOnDelete();
            $table->string('carrier_name', 64)->nullable()->after('carrier'); // "another courier": the name the store typed
            $table->string('carrier_reference', 100)->nullable()->after('tracking_number'); // the courier's own id, if any
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedSmallInteger('pieces')->default(1);
            $table->unsignedBigInteger('cod_amount_minor')->default(0);
            $table->string('cod_status', 16)->default('none'); // none | pending | to_remit | remitted
            $table->timestamp('cod_remitted_at')->nullable();
            $table->string('cod_remittance_reference', 100)->nullable();
            $table->string('booking_status', 16)->nullable(); // booking | booked | failed | unknown (couriers with an API)
            $table->string('booking_error', 255)->nullable();
            $table->string('label_path')->nullable(); // private disk; served only through the label route
            $table->string('label_source', 16)->nullable(); // courier | platform
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('restocked_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();
            $table->unsignedSmallInteger('poll_failures')->default(0);
            $table->index(['store_id', 'cod_status']);
            $table->index(['next_poll_at']);
            $table->index(['store_id', 'direction', 'status']);
        });

        Schema::table('shipment_tracking_events', function (Blueprint $table) {
            $table->string('event_key', 64)->nullable()->after('carrier_event_code');
            $table->unique(['shipment_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::table('shipment_tracking_events', function (Blueprint $table) {
            $table->dropUnique(['shipment_id', 'event_key']);
            $table->dropColumn('event_key');
        });
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'cod_status']);
            $table->dropIndex(['next_poll_at']);
            $table->dropIndex(['store_id', 'direction', 'status']);
            $table->dropConstrainedForeignId('return_request_id');
            $table->dropConstrainedForeignId('courier_account_id');
            $table->dropColumn(['direction', 'carrier_name', 'carrier_reference', 'weight_grams', 'pieces', 'cod_amount_minor', 'cod_status', 'cod_remitted_at',
                'cod_remittance_reference', 'booking_status', 'booking_error', 'label_path', 'label_source', 'cancelled_at', 'restocked_at',
                'last_polled_at', 'next_poll_at', 'poll_failures']);
        });
        Schema::dropIfExists('courier_accounts');
    }
};
