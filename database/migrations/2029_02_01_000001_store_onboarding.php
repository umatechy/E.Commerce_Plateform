<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B44 — gap G23, owner decision 13 (Module 03 §5–8, §14, §24–26,
 * §38, §55–58): both store creation models through one provisioning
 * service.
 *
 * - business_category: what the store sells (Module 03 §14 store identity;
 *   starter templates in B45 build on it);
 * - created_via: self_service (the customer signed up) or platform (Umar
 *   Techy staff created it for the customer); created_by_user_id: who;
 * - activated_at: when the store was launched (§14 "Activated Date").
 *
 * No new store states: trial, past due and grace period are subscription
 * states (Module 04 §17) and stay there — StoreLifecycle derives the
 * onboarding stage from both instead of storing it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('business_category', 32)->nullable()->after('status');
            $table->string('created_via', 16)->default('self_service')->after('business_category');
            $table->foreignId('created_by_user_id')->nullable()->after('created_via')->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable()->after('created_by_user_id');
            $table->index(['business_category'], 'idx_stores_business_category');
            $table->index(['created_via'], 'idx_stores_created_via');
        });

        // Launched stores: their launch time is not known; their creation time is the closest true value.
        DB::table('stores')->where('status', 'active')->whereNull('activated_at')->update(['activated_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex('idx_stores_business_category');
            $table->dropIndex('idx_stores_created_via');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['business_category', 'created_via', 'activated_at']);
        });
    }
};
