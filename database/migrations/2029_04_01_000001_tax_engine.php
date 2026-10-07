<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B46 — gap G3, owner decision 1 "Tax policy" (2026-09-30): tax is
 * configurable per store and jurisdiction — inclusive or exclusive prices,
 * tax classes, rates, regions — and NO rate is built in. Module 11 §28,
 * Module 05 §43, Module 33 §29, Module 13 §62, Module 14 §36, Module 09
 * §10–12, Module 29 §57–58, §95–96; SRS CHK-007; Bible §116.
 *
 * - tax_classes: groups of products taxed alike ("Standard", "Reduced",
 *   "Exempt goods" — names chosen by the store);
 * - tax_rates: a rate for a class in a country (or any country) and
 *   optionally one region, valid from/to dates; no rows are seeded;
 * - products.tax_class_id: null = the store's default class;
 * - customers.tax_exempt / tax_exemption_reference (Module 11 §28);
 * - orders.prices_include_tax and orders.tax_snapshot: the tax result used,
 *   preserved as it was (Module 29 §58 "historical invoices must preserve
 *   the tax result used", §96 base, rate, amount, rounding policy).
 *
 * Permission tax.manage: Owner and Administrator (a financial and legal
 * setting).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['store_id', 'name'], 'uniq_tax_classes_store_name');
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('tax_class_id')->constrained('tax_classes')->restrictOnDelete();
            $table->string('name', 80);
            $table->char('country', 2)->nullable();   // null: any country
            $table->string('region', 80)->nullable(); // null: the whole country
            $table->unsignedInteger('rate_bps');       // basis points: 1 650 = 16.5 %
            $table->boolean('is_active')->default(true);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'tax_class_id', 'country'], 'idx_tax_rates_lookup');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('tax_class_id')->nullable()->after('currency')->constrained('tax_classes')->nullOnDelete();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('tax_exempt')->default(false);
            $table->string('tax_exemption_reference', 80)->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('prices_include_tax')->default(false)->after('tax_total_minor');
            $table->json('tax_snapshot')->nullable()->after('prices_include_tax');
        });

        DB::table('permissions')->insertOrIgnore([
            ['key' => 'tax.manage', 'group' => 'settings', 'description' => 'Set up tax: classes, rates, regions and how tax is charged (Phase B46)'],
        ]);
        $permission = DB::table('permissions')->where('key', 'tax.manage')->value('id');
        DB::table('roles')->where('is_system', true)->where('slug', 'administrator')->orderBy('id')
            ->chunkById(500, fn ($roles) => DB::table('permission_role')->insertOrIgnore(
                $roles->map(fn ($role) => ['role_id' => $role->id, 'permission_id' => $permission])->all(),
            ));
    }

    public function down(): void
    {
        $permission = DB::table('permissions')->where('key', 'tax.manage')->value('id');
        DB::table('permission_role')->where('permission_id', $permission)->delete();
        DB::table('permissions')->where('key', 'tax.manage')->delete();
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['prices_include_tax', 'tax_snapshot']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['tax_exempt', 'tax_exemption_reference']));
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_class_id');
        });
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_classes');
    }
};
