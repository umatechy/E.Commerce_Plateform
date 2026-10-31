<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase S1 (owner decision 2026-10-30): the platform is closed to the public.
 * A business asks Umar Techy for a store through the home page; the request
 * waits in Super Admin → Store requests, where the team follows it up and
 * creates the store from it (StoreProvisioningService::forCustomer).
 *
 * Contact details are personal data: kept only for following up, removable
 * by the team, and removed with the store if the store is ever deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_store_requests_public_id');
            $table->string('business_name', 160);
            $table->string('contact_name', 120);
            $table->string('email', 190);
            $table->string('phone', 30);
            $table->string('city', 80)->nullable();
            $table->string('business_category', 40)->nullable();
            $table->string('package_code', 64)->nullable();
            $table->string('domain', 190)->nullable(); // the customer's own domain, when they have one
            $table->string('hosting', 16);             // umar_techy | own_server | not_sure
            $table->text('message')->nullable();
            $table->string('status', 16)->default('new'); // new | contacted | store_created | declined
            $table->text('staff_note')->nullable();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'idx_store_requests_status_created');
            $table->index('email', 'idx_store_requests_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_requests');
    }
};
