<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 16 §19/§23 "Static Pages / Content Lifecycle" — static pages
// only (about/contact/policy-style); see
// docs/development/b13-inspection-findings.md "Scope Decision" for why
// Blog/Article/Landing-Page/Content-Block engines are deferred. `body`
// is always sanitized server-side before storage (ContentSanitizer) —
// never raw client HTML persisted verbatim.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('body'); // sanitized HTML — see ContentSanitizer
            $table->string('status', 16)->default('draft'); // ContentPageStatus
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'slug'], 'uniq_content_pages_store_id_slug');
            $table->index(['store_id', 'status'], 'idx_content_pages_store_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pages');
    }
};
