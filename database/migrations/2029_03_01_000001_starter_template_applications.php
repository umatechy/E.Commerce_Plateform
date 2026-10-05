<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B45 — Module 07 §20, §101, §105–106, gap G23: a record of each
 * starter template applied to a store (which one, which version, by whom,
 * and what it added). The structure itself is copied into the store's own
 * categories, attributes and brands; this table is the history only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starter_template_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('template_key', 32);
            $table->unsignedSmallInteger('template_version');
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('summary');
            $table->timestamps();
            $table->index(['store_id', 'created_at'], 'idx_starter_template_applications_store');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starter_template_applications');
    }
};
