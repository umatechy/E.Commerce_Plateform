<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 29 (Phase B23) — the billing side of a subscription. The status
// and period columns from Module 04 stay the source of truth for access;
// these add how and when the store is charged.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_interval', 16)->default('monthly')->after('status');
            $table->char('currency', 3)->nullable()->after('billing_interval');
            // Periods are computed from the anchor (anchor + n intervals),
            // so month-end dates never drift (Jan 31 → Feb 28 → Mar 31).
            $table->timestamp('billing_anchor_at')->nullable()->after('currency');
            $table->timestamp('current_period_started_at')->nullable()->after('grace_period_ends_at');
            $table->boolean('cancel_at_period_end')->default(false)->after('current_period_ends_at');
            $table->timestamp('cancellation_requested_at')->nullable()->after('cancel_at_period_end');
            // Set only when the billing engine suspends for non-payment, so
            // a payment never lifts a suspension a Super Admin imposed.
            $table->timestamp('billing_suspended_at')->nullable()->after('cancellation_requested_at');

            $table->index(['status', 'current_period_ends_at'], 'idx_subscriptions_status_period_end');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('idx_subscriptions_status_period_end');
            $table->dropColumn([
                'billing_interval', 'currency', 'billing_anchor_at', 'current_period_started_at',
                'cancel_at_period_end', 'cancellation_requested_at', 'billing_suspended_at',
            ]);
        });
    }
};
