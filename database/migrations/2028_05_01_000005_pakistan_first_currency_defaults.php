<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 2026-10-03: the platform is for Pakistan first. A store's
 * currency is PKR unless it chooses another; the platform offers PKR,
 * USD, EUR, GBP, AED and SAR.
 *
 * The code defaults change for every install (SettingRegistry). This
 * migration brings an existing database in line without converting a
 * single amount (nothing is ever converted between currencies):
 *
 * 1. A saved `platform.supported_currencies` list gets the six currencies
 *    added (PKR first); currencies already in it stay.
 * 2. A store that never chose a currency and already has amounts in
 *    another currency (products, orders, promotions, paid shipping
 *    rates — before this decision everything defaulted to USD) is
 *    pinned to USD, so its prices keep meaning what they meant.
 * 3. Every other store without a choice now uses PKR. Its free pickup
 *    rate and open carts, which carried the old USD placeholder, follow
 *    (zero is zero in any currency, and a cart holds no money of its own).
 * 4. Products saved without any currency get their store's currency
 *    (an order for one could not be saved before).
 *
 * Each pinned or changed value is written as a setting revision, so the
 * store's settings history shows it.
 */
return new class extends Migration
{
    private const SUPPORTED = ['PKR', 'USD', 'EUR', 'GBP', 'AED', 'SAR'];
    private const REASON = 'Owner decision 2026-10-03: Pakistan first (PKR); existing amounts are never converted.';

    public function up(): void
    {
        $now = now();

        // 1. The platform's list.
        $row = DB::table('platform_settings')->where('key', 'platform.supported_currencies')->first();
        if ($row !== null) {
            $saved = json_decode((string) $row->value, true)[0] ?? [];
            $list = array_values(array_unique([...self::SUPPORTED, ...array_map('strtoupper', (array) $saved)]));
            if ($list !== $saved) {
                DB::table('platform_settings')->where('id', $row->id)->update(['value' => json_encode([$list]), 'updated_at' => $now]);
                $this->revision(null, 'platform.supported_currencies', $list, 'platform', $now);
            }
            Cache::forget('settings:platform:platform.supported_currencies');
        }

        // 2–4. Every store.
        foreach (DB::table('stores')->pluck('id') as $storeId) {
            $chosen = DB::table('store_settings')->where('store_id', $storeId)->where('key', 'store.default_currency')->exists();

            if (! $chosen) {
                $hasOtherAmounts = DB::table('products')->where('store_id', $storeId)->whereNotNull('currency')->where('currency', '!=', 'PKR')->exists()
                    || DB::table('orders')->where('store_id', $storeId)->where('currency', '!=', 'PKR')->exists()
                    || DB::table('promotions')->where('store_id', $storeId)->whereNotNull('currency')->where('currency', '!=', 'PKR')->exists()
                    || DB::table('shipping_rates')->where('store_id', $storeId)->where('currency', '!=', 'PKR')->where('base_cost_minor', '>', 0)->exists();

                if ($hasOtherAmounts) {
                    DB::table('store_settings')->insert(['store_id' => $storeId, 'key' => 'store.default_currency', 'value' => json_encode(['USD']), 'created_at' => $now, 'updated_at' => $now]);
                    $this->revision($storeId, 'store.default_currency', 'USD', 'store', $now);
                } else {
                    DB::table('shipping_rates')->where('store_id', $storeId)->where('currency', 'USD')->where('base_cost_minor', 0)->update(['currency' => 'PKR']);
                    DB::table('carts')->where('store_id', $storeId)->where('currency', 'USD')->where('status', 'active')->update(['currency' => 'PKR']);
                }
            }

            $currency = json_decode((string) DB::table('store_settings')->where('store_id', $storeId)->where('key', 'store.default_currency')->value('value'), true)[0] ?? 'PKR';
            DB::table('products')->where('store_id', $storeId)->whereNull('currency')->update(['currency' => $currency]);
            Cache::forget("settings:store:{$storeId}:store.default_currency");
        }
    }

    public function down(): void
    {
        // Data only: nothing is reverted (no amount was converted).
    }

    private function revision(?int $storeId, string $key, mixed $value, string $scope, \DateTimeInterface $at): void
    {
        DB::table('setting_revisions')->insert([
            'scope' => $scope, 'store_id' => $storeId, 'key' => $key, 'value' => json_encode([$value]),
            'changed_by_user_id' => null, 'reason' => self::REASON, 'created_at' => $at,
        ]);
    }
};
