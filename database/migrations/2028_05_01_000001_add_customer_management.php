<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B32 (gap G7, Module 10): what a store needs to manage its
 * customers.
 *
 * - customers.status (§29–31, §58): active, blocked or archived, with the
 *   reason and when it changed. Blocking and archiving keep every row
 *   and every order (§30 "should not silently delete historical data").
 * - customers.source (§7): how the record came to exist. Existing rows
 *   have none; they were registered or attached by checkout before.
 * - customer_groups (§25): one group per customer, so that later group
 *   pricing (§43) has one answer per customer.
 * - customer_tags + customer_tag_assignments (§24): tenant-scoped tags.
 * - customer_notes (§32): internal only, never in a customer API.
 * - customer_email_verifications (§9, §67, SRS AUTH-002): one-time
 *   links that prove an email address; they also let a verified customer
 *   claim the guest orders placed with that address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_customer_groups_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'name'], 'uniq_customer_groups_store_name');
        });

        Schema::create('customer_tags', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_customer_tags_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('normalized_name', 60);
            $table->timestamps();

            $table->unique(['store_id', 'normalized_name'], 'uniq_customer_tags_store_normalized');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('phone');
            $table->string('status_reason', 500)->nullable()->after('status');
            $table->timestamp('status_changed_at')->nullable()->after('status_reason');
            $table->string('source', 20)->nullable()->after('status_changed_at');
            $table->foreignId('customer_group_id')->nullable()->after('source')->constrained('customer_groups')->nullOnDelete();

            $table->index(['store_id', 'status'], 'idx_customers_store_status');
            $table->index(['store_id', 'phone'], 'idx_customers_store_phone');
            $table->index(['store_id', 'created_at'], 'idx_customers_store_created');
        });

        Schema::create('customer_tag_assignments', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('customer_tag_id')->constrained('customer_tags')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['customer_id', 'customer_tag_id']);
            $table->index(['customer_tag_id'], 'idx_customer_tag_assignments_tag');
        });

        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_customer_notes_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['customer_id', 'created_at'], 'idx_customer_notes_customer_created');
        });

        Schema::create('customer_email_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('email');
            $table->char('token_hash', 64)->unique('uniq_customer_email_verifications_token');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'created_at'], 'idx_customer_email_verifications_customer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_email_verifications');
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('customer_tag_assignments');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['customer_group_id']);
            $table->dropIndex('idx_customers_store_status');
            $table->dropIndex('idx_customers_store_phone');
            $table->dropIndex('idx_customers_store_created');
            $table->dropColumn(['status', 'status_reason', 'status_changed_at', 'source', 'customer_group_id']);
        });

        Schema::dropIfExists('customer_tags');
        Schema::dropIfExists('customer_groups');
    }
};
