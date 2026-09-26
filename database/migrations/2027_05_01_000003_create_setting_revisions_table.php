<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 33 §9-10/§31-32 "Versioning / Audit History / Rollback" —
// append-only ledger, same 2-in-1 collapse pattern (audit + rollback
// source) as every append-only ledger since Phase B7's
// PaymentTransaction. `store_id` is nullable here ONLY (unlike
// store_settings itself) because a single revision ledger legitimately
// spans BOTH scopes — no uniqueness constraint is ever needed on this
// table (it is never looked up by anything other than its own primary
// key or a (scope, store_id, key) filter for display), so the
// nullable-NULL-uniqueness concern that ruled out a shared settings
// table does not apply to a pure history log.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 16); // SettingScope
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            $table->string('key');
            $table->json('value'); // already-encrypted for secret-typed settings — never plaintext, even here
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['scope', 'store_id', 'key', 'created_at'], 'idx_setting_revisions_scope_store_id_key_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_revisions');
    }
};
