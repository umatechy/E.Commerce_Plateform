<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Services;

use App\Domain\Affiliates\Models\Affiliate;
use App\Domain\Affiliates\Models\AffiliateCommission;
use App\Domain\Affiliates\Models\AffiliateProgram;
use App\Domain\Affiliates\Support\AffiliateRules;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase B57 (Module 28 §8–16, §40–41, §66, §68) — who gets credit for an
 * order, and the commission it earns.
 *
 * Landing: a storefront page opened with ?ref=CODE (any page, any of the
 * store's addresses) checks the code and hands the browser a token the
 * server signed (store, affiliate, time). The visit is counted once per
 * visitor network and day; nothing about the visitor is stored.
 *
 * Checkout: the storefront sends the token with the order. The server
 * checks the signature, the store, the attribution window and that the
 * affiliate and program are active — the browser decides nothing (§2.3).
 * A coupon linked to an affiliate wins over a link (§68: explicit code
 * first). The commission is calculated from the order the server just
 * made, with the program's version kept as a snapshot (§2.4, §66).
 *
 * Any failure here is swallowed by the caller: the affiliate program never
 * stops a shopper from ordering (§60, §87).
 */
final class AffiliateAttribution
{
    public function program(): ?AffiliateProgram
    {
        if (! app(EntitlementService::class)->hasFeature('affiliate.programs')) {
            return null;
        }

        return AffiliateProgram::query()->with('currentVersion')->where('status', 'active')->orderBy('id')->first();
    }

    /** Whether the shop shows its "Become an affiliate" page: a running program that takes applications. */
    public function joinOpen(): bool
    {
        $program = $this->program();

        return $program !== null && $program->currentVersion !== null && $program->accepts_applications;
    }

    /**
     * A storefront page opened through a referral link.
     *
     * @return array{token: string, code: string, days: int}|null
     */
    public function fromLanding(Request $request, Store $store): ?array
    {
        $code = AffiliateRules::normalizeCode((string) $request->query('ref', ''));
        if ($code === '' || ! AffiliateRules::validCode($code)) {
            return null;
        }
        $program = $this->program();
        $affiliate = $program === null ? null : Affiliate::query()->where('code', $code)->where('status', 'active')->where('affiliate_program_id', $program->id)->first();
        if ($affiliate === null || $program->currentVersion === null) {
            return null;
        }
        $day = now()->toDateString();
        // Counted once per network and day (§40 click flooding); the address is only a cache key, never stored.
        if (Cache::add('aff-click:'.$affiliate->id.':'.$day.':'.hash('sha256', (string) $request->ip()), 1, now()->endOfDay())) {
            DB::table('affiliate_clicks')->insertOrIgnore(['store_id' => $store->id, 'affiliate_id' => $affiliate->id, 'day' => $day, 'clicks' => 0]);
            DB::table('affiliate_clicks')->where(['affiliate_id' => $affiliate->id, 'day' => $day])->increment('clicks');
        }

        return ['token' => $this->sign($store->id, $affiliate->id, now()->getTimestamp()), 'code' => $code, 'days' => (int) $program->currentVersion->rules['window_days']];
    }

    /** The commission an order earns, or null when it earns none. */
    public function attribute(Order $order, ?string $token): ?AffiliateCommission
    {
        $program = $this->program();
        $version = $program?->currentVersion;
        if ($program === null || $version === null || AffiliateCommission::query()->where('order_id', $order->id)->exists()) {
            return null;
        }
        $rules = $version->rules;
        [$affiliate, $source] = $this->claimant($order, $token, $program, (int) $rules['window_days']);
        if ($affiliate === null) {
            return null;
        }

        $base = max(0, (int) $order->subtotal_minor - (int) $order->discount_total_minor);
        if ($base < (int) $rules['min_order_minor']) {
            return null;
        }
        if ($rules['new_customers_only'] && $this->hasEarlierOrders($order)) {
            return null;
        }
        $amount = AffiliateRules::commission($rules, $base);
        if ($amount <= 0) {
            return null;
        }
        $signals = $this->selfReferralSignals($affiliate, $order);
        $status = $signals === [] ? 'pending' : ($rules['self_referral'] === 'reject' ? 'rejected' : 'review');

        return AffiliateCommission::query()->create([
            'store_id' => $order->store_id, 'affiliate_id' => $affiliate->id, 'affiliate_program_version_id' => $version->id, 'order_id' => $order->id,
            'attribution_source' => $source, 'status' => $status, 'base_minor' => $base, 'amount_minor' => $amount, 'currency' => $order->currency,
            'flags' => $signals === [] ? [] : ['self_referral' => $signals],
            'decision_note' => $status === 'rejected' ? 'Self-referral: the program does not pay for orders the affiliate places.' : null,
            'snapshot' => [
                'program_version' => $version->version, 'commission_type' => $rules['commission_type'], 'rate_bp' => $rules['rate_bp'],
                'fixed_minor' => $rules['fixed_minor'], 'cap_per_order_minor' => $rules['cap_per_order_minor'], 'base' => $rules['base'],
                'order_number' => $order->order_number, 'subtotal_minor' => $order->subtotal_minor, 'discount_total_minor' => $order->discount_total_minor,
                'grand_total_minor' => $order->grand_total_minor, 'hold_days' => $rules['hold_days'],
                'require_delivery' => $rules['require_delivery'], 'require_payment' => $rules['require_payment'], 'code' => $affiliate->code,
            ],
        ]);
    }

    /**
     * Coupon first, then a valid link token (§68).
     *
     * @return array{0: ?Affiliate, 1: string}
     */
    private function claimant(Order $order, ?string $token, AffiliateProgram $program, int $windowDays): array
    {
        $codes = DB::table('order_promotions')->where('order_id', $order->id)->whereNotNull('coupon_code_snapshot')->pluck('coupon_code_snapshot')
            ->map(fn ($c) => mb_strtoupper(trim((string) $c)))->all();
        if ($codes !== []) {
            $byCoupon = Affiliate::query()->where('affiliate_program_id', $program->id)->where('status', 'active')->whereNotNull('coupon_id')
                ->whereIn('coupon_id', DB::table('coupons')->where('store_id', $order->store_id)->whereIn(DB::raw('UPPER(code)'), $codes)->select('id'))->first();
            if ($byCoupon !== null) {
                return [$byCoupon, 'coupon'];
            }
        }
        $claim = $token === null ? null : $this->verify($token);
        if ($claim === null || $claim['store'] !== $order->store_id || $claim['issued'] < now()->subDays($windowDays)->getTimestamp() || $claim['issued'] > now()->addMinute()->getTimestamp()) {
            return [null, ''];
        }
        $affiliate = Affiliate::query()->where('id', $claim['affiliate'])->where('affiliate_program_id', $program->id)->where('status', 'active')->first();

        return [$affiliate, 'link'];
    }

    /** @return list<string> what makes the order look like the affiliate's own (§41: signals, not proof) */
    private function selfReferralSignals(Affiliate $affiliate, Order $order): array
    {
        $signals = [];
        if ($affiliate->customer_id !== null && $affiliate->customer_id === $order->customer_id) {
            $signals[] = 'same_customer_account';
        }
        $orderEmail = mb_strtolower((string) ($order->customer->email ?? $order->guest_email ?? ''));
        if ($orderEmail !== '' && $orderEmail === $affiliate->email) {
            $signals[] = 'same_email';
        }
        $digits = fn (?string $p) => substr((string) preg_replace('/\D+/', '', (string) $p), -10);
        $orderPhone = $digits($order->customer->phone ?? $order->guest_phone);
        if ($affiliate->phone !== null && strlen($orderPhone) === 10 && $orderPhone === $digits($affiliate->phone)) {
            $signals[] = 'same_phone';
        }

        return array_values(array_unique($signals));
    }

    private function hasEarlierOrders(Order $order): bool
    {
        $email = mb_strtolower((string) ($order->customer->email ?? $order->guest_email ?? ''));

        return Order::query()->where('id', '<', $order->id)->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->when($order->customer_id !== null, fn ($w) => $w->orWhere('customer_id', $order->customer_id))
                ->when($email !== '', fn ($w) => $w->orWhereRaw('LOWER(guest_email) = ?', [$email])))
            ->exists();
    }

    private function sign(int $storeId, int $affiliateId, int $issued): string
    {
        $payload = rtrim(strtr(base64_encode("{$storeId}.{$affiliateId}.{$issued}"), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', 'affiliate-ref:'.$payload, (string) config('app.key'));
    }

    /** @return array{store: int, affiliate: int, issued: int}|null */
    private function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! hash_equals(hash_hmac('sha256', 'affiliate-ref:'.$parts[0], (string) config('app.key')), $parts[1])) {
            return null;
        }
        $fields = explode('.', (string) base64_decode(strtr($parts[0], '-_', '+/'), true));
        if (count($fields) !== 3) {
            return null;
        }

        return ['store' => (int) $fields[0], 'affiliate' => (int) $fields[1], 'issued' => (int) $fields[2]];
    }
}
