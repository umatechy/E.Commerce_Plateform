<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase B6 — Customer becomes its own authenticatable identity
// (Module 10 §3). Additive only; a guest-order Customer row (Phase B5)
// simply has password = NULL and can never authenticate — see
// Customer::isRegistered().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('password')->nullable()->after('phone');
            $table->timestamp('email_verified_at')->nullable()->after('password');
            $table->rememberToken()->after('email_verified_at');
        });

        // Module 10 §12: prevent duplicate registered accounts for the
        // same email within a store — a guest Customer row created
        // during checkout (Phase B5) does NOT yet enforce this (a guest
        // may check out multiple times with the same email, creating
        // multiple guest rows, which Module 09 §8 explicitly allows —
        // "should not require a permanent account"). The uniqueness
        // constraint therefore applies ONLY where a password is set
        // (i.e. only among REGISTERED accounts), enforced at the
        // application layer in CustomerAuthController::register()
        // (checked before insert) rather than a partial/conditional
        // database unique index, since MySQL does not support a
        // WHERE-conditional unique constraint directly — documented
        // trade-off, consistent with prior ADR-003 precedent for
        // constraints a plain UNIQUE index cannot safely express.
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['password', 'email_verified_at', 'remember_token']);
        });
    }
};
