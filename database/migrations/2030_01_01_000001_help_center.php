<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B54 (gap G12; SRS SUP-001, SUP-005, SUP-006; Module 34 §6–15,
 * §24–25, §28–34, §43) — the knowledge base, its revisions and review
 * workflow, feedback, search analytics and release notes.
 *
 * Help articles are platform content: no store owns them. Only the
 * feedback a store's staff send carries a store_id (purged with the store).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80);                      // stable article id (Module 34 §72.1), shared by its translations
            $table->string('locale', 8)->default('en');
            $table->string('source', 10);                   // bundled (resources/help, reviewed in the repository) | staff (written in the Super Admin)
            $table->string('status', 12);                   // draft | in_review | published | deprecated | archived
            // The live content: what readers see. Edits wait in help_article_revisions until approved.
            $table->string('title', 160);
            $table->string('summary', 300);
            $table->mediumText('body');                     // Markdown
            $table->string('category', 30);
            $table->string('type', 20);                     // guide | how_to | troubleshooting | faq | reference | runbook
            $table->string('audience', 12);                 // customer | merchant | owner | developer | platform
            $table->string('module', 2)->nullable();        // owning blueprint module, 01–35 (Module 34 §10)
            $table->json('tags');
            $table->json('keywords');
            $table->json('error_codes');
            $table->json('contexts');                       // admin page paths this article helps with (contextual help)
            $table->json('related');                        // other article keys
            $table->string('applies_from', 10);             // product version the article describes (Module 34 §8)
            $table->string('applies_until', 10)->nullable();
            $table->string('owner', 80);                    // who keeps it accurate (Module 34 §9.1)
            $table->date('review_due_on')->nullable();
            $table->string('replaced_by', 80)->nullable();  // a deprecated article points to its replacement (§8.4)
            $table->unsignedInteger('revision')->default(0); // the published revision; 0 = never published
            $table->unsignedInteger('translation_source_revision')->nullable(); // a translation: the English revision it was made from (§60.2)
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewed_by_label', 80)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->char('bundle_hash', 64)->nullable();    // SHA-256 of the file last loaded from resources/help
            $table->boolean('bundle_update_available')->default(false);
            $table->timestamps();
            $table->unique(['key', 'locale']);
            $table->index(['status', 'audience', 'locale']);
            $table->index('category');
        });

        Schema::create('help_article_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_article_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('state', 12);                    // draft | in_review | published | superseded | rejected
            $table->json('content');                        // every editable field, as it was
            $table->string('change_note', 300)->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['help_article_id', 'revision']);
            $table->index(['help_article_id', 'state']);
        });

        Schema::create('help_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_article_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');            // the version the reader saw (§28)
            $table->string('locale', 8);
            $table->boolean('helpful');
            $table->string('reason', 12)->nullable();       // missing | outdated | unclear | incorrect | other
            $table->string('comment', 500)->nullable();
            $table->string('surface', 12);                  // admin | storefront
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['help_article_id', 'created_at']);
            $table->index('resolved_at');
        });

        // Search analytics (§29): what was looked for and whether anything
        // was found. No user, no store, no IP (§29 "no unnecessary personal data").
        Schema::create('help_searches', function (Blueprint $table) {
            $table->id();
            $table->string('query', 100);
            $table->string('surface', 12);
            $table->string('locale', 8);
            $table->unsignedSmallInteger('results');
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
        });

        Schema::create('help_release_notes', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20)->unique();
            $table->string('title', 160);
            $table->string('summary', 500);
            $table->json('sections');                       // added, changed, fixed, security, deprecated, removed, breaking, migration, known_issues
            $table->string('status', 10);                   // draft | published
            $table->date('released_on')->nullable();        // set when it is published, never guessed
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->char('bundle_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('help_seen_release', 20)->nullable(); // the newest release note this user has seen
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('help_seen_release'));
        Schema::dropIfExists('help_release_notes');
        Schema::dropIfExists('help_searches');
        Schema::dropIfExists('help_feedback');
        Schema::dropIfExists('help_article_revisions');
        Schema::dropIfExists('help_articles');
    }
};
