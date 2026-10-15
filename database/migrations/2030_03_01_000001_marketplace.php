<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B56 (gap G19; Module 27; SRS APP-001 to APP-007) — the app
 * marketplace: a platform registry of apps and their versions, each
 * store's installations, and the one-time codes an app exchanges for its
 * credentials (the OAuth authorization-code foundation, §34).
 *
 * An installation acts only through the store's own developer
 * application, API key and webhook subscription (B18): no app reads or
 * writes the database directly (§5, §27).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_apps', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('slug', 60)->unique();
            $table->string('name', 80);
            $table->string('tagline', 160);
            $table->text('description');
            $table->string('category', 24);
            $table->string('publisher_name', 120);
            $table->string('publisher_website', 255)->nullable();
            $table->string('support_email', 190)->nullable();
            $table->string('privacy_url', 255);
            $table->string('terms_url', 255)->nullable();
            $table->string('ownership', 16);            // umar_techy | partner | third_party (§7)
            $table->string('trust', 12);                // platform | verified | reviewed | unverified (§73)
            $table->string('status', 12);               // draft | in_review | published | suspended | deprecated | archived (§9)
            $table->string('pricing', 12)->default('free'); // only free apps until paid apps are built (§61–62)
            $table->string('logo_path', 255)->nullable();
            $table->string('client_id', 40)->unique();
            $table->char('client_secret_hash', 64);
            $table->json('redirect_uris');
            $table->string('webhook_url', 255)->nullable();
            $table->json('required_features');          // package features the app needs (Module 04 §55)
            $table->foreignId('current_version_id')->nullable(); // the published version stores install
            $table->string('suspended_reason', 300)->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('marketplace_app_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_app_id')->constrained()->cascadeOnDelete();
            $table->string('version', 20);              // MAJOR.MINOR.PATCH (§18)
            $table->json('scopes');
            $table->json('events');
            $table->json('config_schema');              // fields of the store's settings form (§45)
            $table->text('data_use');                   // what data it uses, why, where it goes, how long (§89)
            $table->text('release_notes')->nullable();
            $table->string('min_platform_version', 10);
            $table->string('status', 12);               // draft | published | superseded
            $table->json('review')->nullable();         // the review checklist, who and when (§16, §71)
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['marketplace_app_id', 'version']);
        });

        Schema::create('app_installations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_app_id')->constrained();
            $table->foreignId('marketplace_app_version_id')->constrained();
            $table->string('status', 24);               // awaiting_connection | active | disabled | suspended | uninstalled (§10)
            $table->string('status_before_suspension', 24)->nullable();
            $table->json('granted_scopes');
            $table->json('granted_events');
            $table->text('config')->nullable();         // encrypted JSON: the store's settings, secret fields included
            $table->foreignId('developer_application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'marketplace_app_id']);
            $table->index(['marketplace_app_id', 'status']);
        });

        Schema::create('app_authorization_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_installation_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->string('redirect_uri', 255);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_authorization_codes');
        Schema::dropIfExists('app_installations');
        Schema::dropIfExists('marketplace_app_versions');
        Schema::dropIfExists('marketplace_apps');
    }
};
