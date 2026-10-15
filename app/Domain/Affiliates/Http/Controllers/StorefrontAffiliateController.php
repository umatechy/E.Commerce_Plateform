<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Http\Controllers;

use App\Domain\Affiliates\Models\Affiliate;
use App\Domain\Affiliates\Models\AffiliateAdjustment;
use App\Domain\Affiliates\Models\AffiliateCommission;
use App\Domain\Affiliates\Models\AffiliatePayout;
use App\Domain\Affiliates\Services\AffiliateAttribution;
use App\Domain\Affiliates\Services\AffiliateDirectory;
use App\Domain\Affiliates\Services\AffiliateLedger;
use App\Domain\Affiliates\Services\AffiliateProgramService;
use App\Domain\Seo\Services\SeoResolver;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase B57 (Module 28 §43 "affiliate dashboard", §91, §135) — the
 * affiliate's side, on the store's own storefront:
 *
 * - apply: the join form (when the program takes applications). The
 *   answer is the same whether or not the address was already known.
 * - portal: the affiliate's private page, opened with the token from
 *   their link (sent in a header, never in the URL). It shows their link
 *   and code, clicks, orders and earnings, payouts, and where they are
 *   paid — never a customer's name, address or contact (§30, §76).
 */
final class StorefrontAffiliateController
{
    public function apply(Request $request, AffiliateAttribution $attribution, AffiliateDirectory $directory): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:1000'],
            'accept_terms' => ['accepted'],
            'website' => ['nullable', 'string', 'max:0'], // honeypot: people never see this field
        ]);
        $program = $attribution->program();
        abort_if($program === null || ! $program->accepts_applications, 404);
        $directory->apply($program, $data);

        return response()->json(['message' => 'Thank you. The store reviews each application; if you are approved, we email you the link to your private page.'], 202);
    }

    public function portal(Request $request, AffiliateDirectory $directory, AffiliateLedger $ledger, SeoResolver $seo): JsonResponse
    {
        $affiliate = $this->affiliate($request, $directory);
        $store = Store::query()->withoutGlobalScopes()->findOrFail($affiliate->store_id);
        $version = $affiliate->program->currentVersion;
        $since = now()->subDays(30)->toDateString();
        $commissions = AffiliateCommission::query()->where('affiliate_id', $affiliate->id)->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => [
            'name' => $affiliate->name,
            'status' => $affiliate->status,
            'code' => $affiliate->code,
            'link' => rtrim($seo->forStoreHome($store)->canonicalUrl, '/').'/?ref='.$affiliate->code,
            'coupon' => $affiliate->coupon_id === null ? null : DB::table('coupons')->where('id', $affiliate->coupon_id)->value('code'),
            'program' => ['name' => $affiliate->program->name, 'running' => $affiliate->program->status === 'active',
                'summary' => $version === null ? [] : AffiliateProgramService::summary($version->rules, $version->currency), 'terms' => $version?->terms],
            'stats' => [
                'clicks_30d' => (int) DB::table('affiliate_clicks')->where('affiliate_id', $affiliate->id)->where('day', '>=', $since)->sum('clicks'),
                'orders' => AffiliateCommission::query()->where('affiliate_id', $affiliate->id)->whereIn('status', AffiliateCommission::LIVE)->count(),
            ],
            'balance' => $ledger->balances($affiliate->id)[$affiliate->id] ?? ['pending_minor' => 0, 'payable_minor' => 0, 'paid_minor' => 0, 'in_payout_minor' => 0],
            'currency' => $version?->currency,
            'commissions' => $commissions->map(fn (AffiliateCommission $c) => [
                'date' => $c->created_at?->toIso8601String(), 'sale_minor' => $c->base_minor, 'commission_minor' => $c->net(), 'status' => $c->status, 'source' => $c->attribution_source,
            ])->all(),
            'payouts' => AffiliatePayout::query()->where('affiliate_id', $affiliate->id)->where('status', '!=', 'cancelled')->orderByDesc('id')->limit(20)->get()
                ->map(fn (AffiliatePayout $p) => ['date' => ($p->paid_at ?? $p->created_at)?->toIso8601String(), 'amount_minor' => $p->amount_minor, 'status' => $p->status, 'reference' => $p->reference])->all(),
            // Corrections to the balance, with the store's reason (an order number at most, never a customer).
            'adjustments' => AffiliateAdjustment::query()->where('affiliate_id', $affiliate->id)->orderByDesc('id')->limit(20)->get()
                ->map(fn (AffiliateAdjustment $a) => ['date' => $a->created_at->toIso8601String(), 'amount_minor' => (int) $a->amount_minor, 'reason' => $a->reason])->all(),
            'payout_details' => AffiliateDirectory::maskedPayout($affiliate),
        ]]);
    }

    public function payoutDetails(Request $request, AffiliateDirectory $directory): JsonResponse
    {
        $affiliate = $this->affiliate($request, $directory);
        $data = $request->validate(AffiliateAdminController::payoutRules());
        $directory->setPayoutDetails($affiliate, $data['method'], $data, null);

        return response()->json(['data' => AffiliateDirectory::maskedPayout($affiliate->refresh()), 'message' => 'Saved.']);
    }

    private function affiliate(Request $request, AffiliateDirectory $directory): Affiliate
    {
        $affiliate = $directory->byToken((string) $request->header('X-Affiliate-Token', ''));
        abort_if($affiliate === null, 404, 'This private link does not work any more. Ask the store for a new one.');

        return $affiliate;
    }
}
