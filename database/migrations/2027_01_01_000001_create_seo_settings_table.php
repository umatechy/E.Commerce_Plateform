<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 16 §5-6/§43 "SEO Scope / Data Model" — one row per SEO-able
// entity (Store/Product/Category/Brand/ContentPage), via a polymorphic
// (seoable_type, seoable_id) pair. NEVER duplicates the entity's own
// authoritative fields (name/description/etc.) — only SEO-SPECIFIC
// overrides live here (Core Principle).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('seoable_type', 24); // SeoableType
            $table->unsignedBigInteger('seoable_id')->nullable(); // null for the Store's own "home page" SEO row
            $table->string('title')->nullable();
            $table->string('meta_description', 320)->nullable(); // Module 16 §7's own advisory-length guidance
            $table->string('canonical_override')->nullable();
            $table->string('og_title')->nullable();
            $table->string('og_description', 320)->nullable();
            $table->string('og_image_url')->nullable();
            $table->string('robots_index', 16)->default('index'); // RobotsDirective
            $table->string('robots_follow', 16)->default('follow'); // RobotsDirective
            $table->timestamps();

            $table->unique(['store_id', 'seoable_type', 'seoable_id'], 'uniq_seo_settings_store_id_type_id');
            $table->index(['store_id', 'seoable_type'], 'idx_seo_settings_store_id_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_settings');
    }
};
