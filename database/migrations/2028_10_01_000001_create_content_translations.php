<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B38 — SRS LOC-002, Module 06 §101, Module 07 §99, Module 35 §4.3:
 * translations of store content (product, category and brand names and
 * descriptions), one row per item, language and field. The original text
 * stays on the item itself in the store's default language; a missing
 * translation falls back to it. Identifiers (slugs, SKUs, ids) are never
 * translated (LOC-003).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('translatable_type', 24); // product | category | brand
            $table->unsignedBigInteger('translatable_id');
            $table->string('locale', 8);
            $table->string('field', 32);
            $table->text('value');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'translatable_type', 'translatable_id', 'locale', 'field'], 'uniq_content_translations_item_locale_field');
            $table->index(['store_id', 'translatable_type', 'locale'], 'idx_content_translations_store_type_locale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_translations');
    }
};
