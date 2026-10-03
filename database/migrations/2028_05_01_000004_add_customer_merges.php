<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 10 §56 "Customer Merge" (CustomerMergeRecord, §"Concurrent
 * customer merge"): one customer record joined into another of the same
 * store. The source is kept, archived and marked as merged, so the
 * history stays traceable; `customer_merges` records what moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('merged_into_customer_id')->nullable()->after('customer_group_id')->constrained('customers')->nullOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merged_into_customer_id');
        });

        Schema::create('customer_merges', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_customer_merges_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('source_customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('target_customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->json('moved'); // counts per kind, no personal data
            $table->timestamp('created_at')->useCurrent();

            // A record is merged once.
            $table->unique('source_customer_id', 'uniq_customer_merges_source');
            $table->index(['store_id', 'target_customer_id'], 'idx_customer_merges_store_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_merges');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_customer_id');
            $table->dropColumn('merged_at');
        });
    }
};
