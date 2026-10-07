<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B45 follow-up — Module 07 §101 (template marketplace foundation),
 * Module 03 §52 (store template / cloning, a controlled workflow): starter
 * templates managed by Umar Techy staff. PLATFORM data (no store_id scope).
 *
 * - source built_in: one of the templates in code (StarterTemplates); a row
 *   only exists when staff changed its availability; `definition` is null;
 * - source store: a template saved by staff from an existing store's
 *   structure (categories, attributes, filters, brands, theme choice — never
 *   products, customers, orders, media, domains or credentials).
 *
 * is_active: usable at all; offered_to_stores: shown to store owners on
 * their Categories page (otherwise only staff use it when creating stores).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_starter_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique('uniq_platform_starter_templates_key');
            $table->string('source', 16);
            $table->string('name', 120)->nullable();
            $table->string('summary', 500)->nullable();
            $table->string('business_category', 32)->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->json('definition')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('offered_to_stores')->default(true);
            $table->foreignId('source_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_starter_templates');
    }
};
