<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B57 (gap G20, part 1; Module 28) — the affiliate program engine and
 * the store affiliate program.
 *
 * - A program's rules live in immutable versions; every commission keeps
 *   the version it was calculated with (§2.4, §108).
 * - Attribution (who gets credit) and commission (how much) are separate
 *   records from payouts (what was paid) (§2.5–2.6).
 * - Corrections after a payout are adjustments, never edits (§39, §69).
 *
 * Every table is store-scoped and is purged with the store's data.
 */
return new class extends Migration
{
    private const PACKAGES = ['basic' => false, 'business' => true, 'premium' => true];

    private const PERMISSIONS = [
        'affiliates.view' => ['View the affiliate program, affiliates and commissions (Phase B57)', ['administrator', 'manager', 'content-marketing']],
        'affiliates.manage' => ['Run the affiliate program: rules, affiliates, commission reviews (Phase B57)', ['administrator', 'manager', 'content-marketing']],
        'affiliates.payouts' => ['Create and record affiliate payouts (Phase B57)', ['administrator']],
    ];

    public function up(): void
    {
        Schema::create('affiliate_programs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('status', 12);                  // draft | active | paused | archived (§7)
            $table->foreignId('current_version_id')->nullable();
            $table->boolean('accepts_applications')->default(true);
            $table->timestamps();
            $table->index(['store_id', 'status']);
        });

        Schema::create('affiliate_program_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_program_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('rules');                          // commission, base, window, hold, qualification, limits (§18–24, §67)
            $table->text('terms');                          // what affiliates agree to (§47)
            $table->char('currency', 3);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['affiliate_program_id', 'version']);
        });

        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_program_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 30)->nullable();
            $table->string('status', 12);                   // pending | active | paused | suspended | rejected (§6)
            $table->string('code', 20);                     // referral code, upper case (§9)
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete(); // §10: orders with this coupon are theirs
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete(); // the same person as a shopper (self-referral, §41)
            $table->string('payout_method', 20)->nullable(); // bank_transfer | jazzcash | easypaisa
            $table->text('payout_details')->nullable();      // encrypted account title and number (§36)
            $table->char('portal_token_hash', 64)->nullable()->unique();
            $table->unsignedInteger('terms_version')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('source', 10);                   // invited | applied
            $table->string('application_note', 1000)->nullable();
            $table->string('status_reason', 300)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'code']);
            $table->unique(['store_id', 'email']);
            $table->index(['store_id', 'status']);
        });

        // §12–14 with data minimisation (§76): clicks counted per affiliate and day, nothing about the visitor.
        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('clicks')->default(0);
            $table->unique(['affiliate_id', 'day']);
        });

        Schema::create('affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12);                   // pending | paid | cancelled (§34)
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('method', 20);
            $table->string('reference', 120)->nullable();  // the bank or wallet transaction id (§38)
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'status']);
        });

        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_program_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete(); // one commission per order (§16)
            $table->string('attribution_source', 10);      // link | coupon (§12, §68)
            $table->string('status', 12);                   // pending | review | approved | paid | rejected | reversed (§21)
            $table->bigInteger('base_minor');
            $table->bigInteger('amount_minor');             // as calculated, never changed (§2.4)
            $table->bigInteger('reversed_minor')->default(0); // taken back for refunds before payout (§23)
            $table->char('currency', 3);
            $table->json('snapshot');                       // rule, rate, order figures (§66)
            $table->json('flags');                          // risk signals, e.g. self_referral (§41–42)
            $table->timestamp('hold_until')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('affiliate_payout_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'status']);
            $table->index(['affiliate_id', 'status']);
        });

        // §39, §69: what changes a balance after the fact — a refund after payout, or a correction.
        Schema::create('affiliate_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_commission_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount_minor');             // negative takes back, positive adds
            $table->char('currency', 3);
            $table->string('reason', 300);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // null: automatic (a refund)
            $table->foreignId('affiliate_payout_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['affiliate_id', 'affiliate_payout_id']);
        });

        foreach (self::PACKAGES as $code => $enabled) {
            $packageId = DB::table('packages')->where('code', $code)->value('id');
            if ($packageId !== null && ! DB::table('package_entitlements')->where('package_id', $packageId)->where('key', 'affiliate.programs')->exists()) {
                DB::table('package_entitlements')->insert([
                    'package_id' => $packageId, 'key' => 'affiliate.programs', 'type' => 'feature',
                    'boolean_value' => $enabled, 'is_unlimited' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach (self::PERMISSIONS as $key => [$description, $roles]) {
            DB::table('permissions')->insertOrIgnore([['key' => $key, 'group' => 'marketing', 'description' => $description]]);
            $permission = DB::table('permissions')->where('key', $key)->value('id');
            DB::table('roles')->where('is_system', true)->whereIn('slug', $roles)->orderBy('id')
                ->chunkById(500, fn ($rows) => DB::table('permission_role')->insertOrIgnore(
                    $rows->map(fn ($role) => ['role_id' => $role->id, 'permission_id' => $permission])->all(),
                ));
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('package_entitlements')->where('key', 'affiliate.programs')->delete();
        Schema::dropIfExists('affiliate_adjustments');
        Schema::dropIfExists('affiliate_commissions');
        Schema::dropIfExists('affiliate_payouts');
        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliates');
        Schema::dropIfExists('affiliate_program_versions');
        Schema::dropIfExists('affiliate_programs');
    }
};
