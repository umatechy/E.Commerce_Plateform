<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G1 — a message that carries a secret (a staff invitation link, a
 * password-reset link, a guest's private ticket link) keeps the secret
 * only here, encrypted, until the message is delivered. `body` holds the
 * same text with the secret replaced by "[hidden]", so the admin
 * notification log and API never show a usable link (B25 security review
 * limitation). The job clears this column once delivery has finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_messages', function (Blueprint $table) {
            $table->text('sealed_body')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('notification_messages', function (Blueprint $table) {
            $table->dropColumn('sealed_body');
        });
    }
};
