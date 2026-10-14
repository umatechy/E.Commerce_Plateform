<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B52 — security and operations (Module 32 §7, §9, §58–64, §65, §85;
 * Module 30 §3–5, §58–61, §87–88; SRS AUTH-005; open findings 5, 6 and 10
 * of docs/security/b29-security-baseline.md).
 *
 * - user_sessions: every signed-in staff session (device, place, last
 *   use), so a person can see and end them, and privileged sessions can
 *   time out sooner. Only a hash of the session id is stored.
 * - staff_password_resets: "forgot password" for staff accounts (tokens
 *   stored as SHA-256, 60 minutes, single use).
 * - security_events: what security monitoring watches (failed sign-ins,
 *   MFA changes, role changes, exports, emergency switches …), kept for a
 *   limited time; the audit trail stays the record of actions.
 * - security_incidents / security_incident_updates: incidents with
 *   severity, status and a timeline that is never edited, only added to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('session_hash', 64)->unique();
            $table->boolean('privileged')->default(false);
            $table->boolean('with_mfa')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 32)->nullable(); // signed_out | revoked | idle_timeout | expired | password_changed | account_deactivated | mfa_reset
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('staff_password_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 48);
            $table->string('severity', 12); // info | low | medium | high | critical
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 120)->nullable(); // masked email, key or store for events without an account
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->json('context')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->boolean('alerted')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['type', 'created_at']);
            $table->index(['severity', 'created_at']);
            $table->index(['user_id', 'type', 'created_at']);
            $table->index(['ip_address', 'type', 'created_at']);
        });

        Schema::create('security_incidents', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('reference', 20)->unique(); // INC-2026-0001
            $table->string('title', 200);
            $table->string('severity', 8); // sev0 … sev4
            $table->string('status', 16); // detected | triaged | investigating | containing | mitigating | resolved | closed
            $table->string('component', 60);
            $table->string('scope', 16); // platform | stores | store | provider
            $table->json('affected_store_ids')->nullable();
            $table->text('impact')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('resolution')->nullable();
            $table->string('postmortem_reference', 300)->nullable();
            $table->timestamps();
            $table->index(['status', 'severity']);
        });

        Schema::create('security_incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('security_incident_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // opened | status | severity | note | owner
            $table->string('from_value', 40)->nullable();
            $table->string('to_value', 40)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incident_updates');
        Schema::dropIfExists('security_incidents');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('staff_password_resets');
        Schema::dropIfExists('user_sessions');
    }
};
