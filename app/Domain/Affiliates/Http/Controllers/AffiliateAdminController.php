<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Http\Controllers;

use App\Domain\Affiliates\Models\Affiliate;
use App\Domain\Affiliates\Models\AffiliateAdjustment;
use App\Domain\Affiliates\Models\AffiliateCommission;
use App\Domain\Affiliates\Models\AffiliatePayout;
use App\Domain\Affiliates\Models\AffiliateProgram;
use App\Domain\Affiliates\Policies\AffiliatePolicy;
use App\Domain\Affiliates\Services\AffiliateDirectory;
use App\Domain\Affiliates\Services\AffiliateLedger;
use App\Domain\Affiliates\Services\AffiliateNotifier;
use App\Domain\Affiliates\Services\AffiliateProgramService;
use App\Domain\Affiliates\Services\AffiliateRefused;
use App\Domain\Affiliates\Support\AffiliateRules;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Promotions\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase B57 (Module 28 §45 "store owner affiliate dashboard", §7, §42, §69,
 * §90) — Marketing → Affiliates: the program and its rules, affiliates and
 * applications, commissions and reviews, balances and payouts.
 *
 * affiliates.view to look, affiliates.manage to run it, affiliates.payouts
 * to pay and correct balances (with step-up on the routes). Everything is
 * the active store's only: route bindings and queries are tenant-scoped.
 */
final class AffiliateAdminController
{
    public function __construct(
        private readonly AffiliateProgramService $programs,
        private readonly AffiliateDirectory $directory,
        private readonly AffiliateLedger $ledger,
        private readonly AffiliateNotifier $notifier,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $user = $this->authorize($request, 'view');
        $program = $this->programs->current();
        $since = now()->subDays(30);
        $balances = $this->ledger->balances();
        $live = AffiliateCommission::query()->whereIn('status', AffiliateCommission::LIVE)->where('created_at', '>=', $since);
        $policy = app(AffiliatePolicy::class);

        return response()->json(['data' => [
            'included' => app(EntitlementService::class)->hasFeature('affiliate.programs'),
            'can_manage' => $policy->manage($user),
            'can_pay' => $policy->payouts($user),
            'program' => $program === null ? null : $this->program($program),
            'currency' => (string) ($program?->currentVersion->currency ?? app(\App\Domain\Settings\Services\ConfigService::class)->get('store.default_currency')),
            'defaults' => AffiliateRules::DEFAULTS,
            'limits' => ['max_window_days' => AffiliateRules::MAX_WINDOW_DAYS, 'max_rate_percent' => AffiliateRules::MAX_RATE_BP / 100],
            'stats' => [
                'clicks_30d' => (int) DB::table('affiliate_clicks')->where('store_id', app(\App\Domain\Tenancy\Support\TenantContext::class)->storeId())->where('day', '>=', $since->toDateString())->sum('clicks'),
                'orders_30d' => (clone $live)->count(),
                'sales_30d_minor' => (int) (clone $live)->sum('base_minor'),
                'commission_30d_minor' => (int) (clone $live)->get()->sum(fn (AffiliateCommission $c) => $c->net()),
                'pending_minor' => array_sum(array_column($balances, 'pending_minor')),
                'payable_minor' => array_sum(array_column($balances, 'payable_minor')),
                'paid_minor' => array_sum(array_column($balances, 'paid_minor')),
                'in_review' => AffiliateCommission::query()->where('status', 'review')->count(),
                'applications' => Affiliate::query()->where('status', 'pending')->count(),
                'active_affiliates' => Affiliate::query()->where('status', 'active')->count(),
            ],
            'coupons' => Coupon::query()->where('is_active', true)->orderBy('code')->limit(500)->get(['id', 'code'])->map(fn (Coupon $c) => ['id' => $c->id, 'code' => $c->code])->all(),
        ]]);
    }

    public function saveProgram(Request $request): JsonResponse
    {
        $user = $this->authorize($request, 'manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'accepts_applications' => ['required', 'boolean'],
            'terms' => ['nullable', 'string', 'max:10000'],
            ...AffiliateRules::validation(),
        ]);
        $program = $this->programs->ensure($user);
        $program = $this->programs->update($user, $program, $data['name'], (bool) $data['accepts_applications'], AffiliateRules::fromInput($data), (string) ($data['terms'] ?? ''));

        return response()->json(['data' => $this->program($program), 'message' => 'Program saved. New rules apply to orders from now on; earlier commissions keep theirs.']);
    }

    public function activate(Request $request): JsonResponse
    {
        $user = $this->authorize($request, 'manage');

        return $this->attempt(function () use ($user) {
            $this->programs->activate($user, $this->programs->ensure($user));

            return response()->json(['message' => 'The program is running. Referral links and codes now count.']);
        });
    }

    public function pause(Request $request): JsonResponse
    {
        $user = $this->authorize($request, 'manage');

        return $this->attempt(function () use ($user) {
            $program = $this->programs->current() ?? throw new AffiliateRefused('no_program', 'There is no program yet.');
            $this->programs->pause($user, $program);

            return response()->json(['message' => 'Paused. New clicks and orders do not count; earned commissions continue.']);
        });
    }

    public function affiliates(Request $request): JsonResponse
    {
        $this->authorize($request, 'view');
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'active', 'paused', 'rejected'])], 'q' => ['nullable', 'string', 'max:100']]);
        $since = now()->subDays(30)->toDateString();
        $balances = $this->ledger->balances();
        $clicks = DB::table('affiliate_clicks')->where('day', '>=', $since)->selectRaw('affiliate_id, sum(clicks) as n')->groupBy('affiliate_id')->pluck('n', 'affiliate_id');
        $orders = AffiliateCommission::query()->whereIn('status', AffiliateCommission::LIVE)->selectRaw('affiliate_id, count(*) as n, sum(base_minor) as sales')->groupBy('affiliate_id')->get()->keyBy('affiliate_id');
        $rows = Affiliate::query()
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('code', 'like', '%'.AffiliateRules::normalizeCode($s).'%')))
            ->orderByRaw("FIELD(status, 'pending', 'active', 'paused', 'rejected')")->orderBy('name')->get();
        $coupons = Coupon::query()->whereIn('id', $rows->pluck('coupon_id')->filter())->pluck('code', 'id');

        return response()->json(['data' => $rows->map(fn (Affiliate $a) => [
            'id' => $a->public_id, 'name' => $a->name, 'email' => $a->email, 'phone' => $a->phone, 'status' => $a->status, 'status_reason' => $a->status_reason,
            'code' => $a->code, 'coupon' => $a->coupon_id === null ? null : ['id' => $a->coupon_id, 'code' => $coupons[$a->coupon_id] ?? null],
            'source' => $a->source, 'application_note' => $a->application_note, 'is_customer' => $a->customer_id !== null,
            'created_at' => $a->created_at?->toIso8601String(), 'approved_at' => $a->approved_at?->toIso8601String(),
            'payout' => AffiliateDirectory::maskedPayout($a),
            'clicks_30d' => (int) ($clicks[$a->id] ?? 0), 'orders' => (int) ($orders[$a->id]->n ?? 0), 'sales_minor' => (int) ($orders[$a->id]->sales ?? 0),
            'balance' => $balances[$a->id] ?? ['pending_minor' => 0, 'payable_minor' => 0, 'paid_minor' => 0, 'in_payout_minor' => 0],
        ])->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->authorize($request, 'manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:190'], 'phone' => ['nullable', 'string', 'max:30'],
            'code' => ['nullable', 'string', 'max:30'], 'coupon_id' => ['nullable', 'integer'],
        ]);

        return $this->attempt(function () use ($user, $data) {
            $program = $this->programs->ensure($user);
            [$affiliate, $token] = $this->directory->add($user, $program, $data);

            return response()->json(['data' => ['id' => $affiliate->public_id, 'code' => $affiliate->code, 'portal_url' => $this->notifier->portalUrl($affiliate, $token)],
                'message' => 'Affiliate added. We emailed them their private page; you can also copy its link now.'], 201);
        });
    }

    public function update(Request $request, Affiliate $affiliate): JsonResponse
    {
        $user = $this->authorize($request, 'manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:30'], 'code' => ['required', 'string', 'max:30'], 'coupon_id' => ['nullable', 'integer']]);

        return $this->attempt(function () use ($user, $affiliate, $data) {
            $this->directory->update($user, $affiliate, $data);

            return response()->json(['message' => 'Saved. Links with the old code stop counting.']);
        });
    }

    public function approve(Request $request, Affiliate $affiliate): JsonResponse
    {
        $user = $this->authorize($request, 'manage');

        return $this->attempt(function () use ($user, $affiliate) {
            $token = $this->directory->approve($user, $affiliate);

            return response()->json(['data' => ['portal_url' => $this->notifier->portalUrl($affiliate, $token)], 'message' => 'Approved. We emailed them their private page.']);
        });
    }

    public function reject(Request $request, Affiliate $affiliate): JsonResponse
    {
        $user = $this->authorize($request, 'manage');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        return $this->attempt(function () use ($user, $affiliate, $data) {
            $this->directory->reject($user, $affiliate, $data['reason'] ?? null);

            return response()->json(['message' => 'Declined.']);
        });
    }

    public function pauseAffiliate(Request $request, Affiliate $affiliate): JsonResponse
    {
        $user = $this->authorize($request, 'manage');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        return $this->attempt(function () use ($user, $affiliate, $data) {
            $this->directory->pause($user, $affiliate, $data['reason'] ?? null);

            return response()->json(['message' => 'Paused. New clicks and orders do not count for them, and no payout is made.']);
        });
    }

    public function resumeAffiliate(Request $request, Affiliate $affiliate): JsonResponse
    {
        $user = $this->authorize($request, 'manage');

        return $this->attempt(function () use ($user, $affiliate) {
            $this->directory->resume($user, $affiliate);

            return response()->json(['message' => 'Active again.']);
        });
    }

    public function portalLink(Request $request, Affiliate $affiliate): JsonResponse
    {
        $user = $this->authorize($request, 'manage');

        return $this->attempt(function () use ($user, $affiliate) {
            $token = $this->directory->newPortalLink($user, $affiliate);

            return response()->json(['data' => ['portal_url' => $this->notifier->portalUrl($affiliate, $token)], 'message' => 'New private link made. The old one stopped working.']);
        });
    }

    public function payoutDetails(Request $request, Affiliate $affiliate): JsonResponse
    {
        $user = $this->authorize($request, 'payouts');
        $data = $request->validate(self::payoutRules());
        $this->directory->setPayoutDetails($affiliate, $data['method'], $data, $user);

        return response()->json(['data' => AffiliateDirectory::maskedPayout($affiliate->refresh()), 'message' => 'Payout details saved.']);
    }

    public function commissions(Request $request): JsonResponse
    {
        $this->authorize($request, 'view');
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'review', 'approved', 'paid', 'rejected', 'reversed'])], 'affiliate' => ['nullable', 'string', 'size:26']]);
        $page = AffiliateCommission::query()->with(['affiliate:id,public_id,name,code', 'order:id,public_id,order_number,status,payment_status'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['affiliate'] ?? null, fn ($q, $id) => $q->whereIn('affiliate_id', Affiliate::query()->where('public_id', $id)->select('id')))
            ->orderByRaw("FIELD(status, 'review', 'pending', 'approved', 'paid', 'reversed', 'rejected')")->orderByDesc('id')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (AffiliateCommission $c) => [
                'id' => $c->public_id, 'status' => $c->status, 'source' => $c->attribution_source,
                'affiliate' => ['id' => $c->affiliate->public_id, 'name' => $c->affiliate->name, 'code' => $c->affiliate->code],
                'order' => ['id' => $c->order->public_id, 'number' => $c->order->order_number, 'status' => $c->order->status->value],
                'base_minor' => $c->base_minor, 'amount_minor' => $c->amount_minor, 'reversed_minor' => $c->reversed_minor, 'net_minor' => $c->net(), 'currency' => $c->currency,
                'rate_percent' => $c->snapshot['commission_type'] === 'percentage' ? ((int) $c->snapshot['rate_bp']) / 100 : null,
                'program_version' => $c->snapshot['program_version'] ?? null, 'flags' => $c->flags, 'note' => $c->decision_note,
                'hold_until' => $c->hold_until?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String(), 'in_payout' => $c->affiliate_payout_id !== null,
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function decide(Request $request, AffiliateCommission $commission): JsonResponse
    {
        $user = $this->authorize($request, 'manage');
        $data = $request->validate(['decision' => ['required', Rule::in(['release', 'reject'])], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($user, $commission, $data) {
            $this->ledger->decide($user, $commission, $data['decision'], $data['note'] ?? null);

            return response()->json(['message' => $data['decision'] === 'release' ? 'Released: it continues like any commission.' : 'Rejected: it will not be paid.']);
        });
    }

    public function payouts(Request $request): JsonResponse
    {
        $this->authorize($request, 'view');
        $rows = AffiliatePayout::query()->with('affiliate:id,public_id,name')->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (AffiliatePayout $p) => [
            'id' => $p->public_id, 'affiliate' => ['id' => $p->affiliate->public_id, 'name' => $p->affiliate->name], 'status' => $p->status,
            'amount_minor' => $p->amount_minor, 'currency' => $p->currency, 'method' => $p->method, 'reference' => $p->reference, 'note' => $p->note,
            'created_at' => $p->created_at?->toIso8601String(), 'paid_at' => $p->paid_at?->toIso8601String(),
        ])->all()]);
    }

    public function createPayout(Request $request, Affiliate $affiliate): JsonResponse
    {
        $user = $this->authorize($request, 'payouts');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($user, $affiliate, $data) {
            $threshold = (int) ($this->programs->current()?->currentVersion?->rules['payout_threshold_minor'] ?? 0);
            $payout = $this->ledger->createPayout($user, $affiliate, $threshold, $data['note'] ?? null);

            // The full account: the store pays it itself (step-up on the route; audited).
            return response()->json(['data' => ['id' => $payout->public_id, 'amount_minor' => $payout->amount_minor, 'pay_to' => $this->directory->revealPayout($user, $affiliate, 'payout '.$payout->public_id)],
                'message' => 'Payout prepared. Pay it, then record the transaction reference.'], 201);
        });
    }

    public function markPaid(Request $request, AffiliatePayout $payout): JsonResponse
    {
        $user = $this->authorize($request, 'payouts');
        $data = $request->validate(['reference' => ['required', 'string', 'min:3', 'max:120']]);

        return $this->attempt(function () use ($user, $payout, $data) {
            $this->ledger->markPaid($user, $payout, $data['reference']);

            return response()->json(['message' => 'Recorded as paid.']);
        });
    }

    /** Where to send a payout still to be paid: the full account (step-up on the route; audited). */
    public function payTo(Request $request, AffiliatePayout $payout): JsonResponse
    {
        $user = $this->authorize($request, 'payouts');
        abort_if($payout->status !== 'pending', 404);

        return response()->json(['data' => $this->directory->revealPayout($user, $payout->affiliate, 'payout '.$payout->public_id)]);
    }

    public function cancelPayout(Request $request, AffiliatePayout $payout): JsonResponse
    {
        $user = $this->authorize($request, 'payouts');

        return $this->attempt(function () use ($user, $payout) {
            $this->ledger->cancelPayout($user, $payout);

            return response()->json(['message' => 'Cancelled. Its commissions are payable again.']);
        });
    }

    public function adjust(Request $request, Affiliate $affiliate): JsonResponse
    {
        $user = $this->authorize($request, 'payouts');
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'not_in:0', 'between:-100000000000,100000000000'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);

        return $this->attempt(function () use ($user, $affiliate, $data) {
            $currency = (string) (AffiliateCommission::query()->where('affiliate_id', $affiliate->id)->value('currency') ?? app(\App\Domain\Settings\Services\ConfigService::class)->get('store.default_currency'));
            $this->ledger->adjust($user, $affiliate, (int) $data['amount_minor'], $currency, $data['reason']);

            return response()->json(['message' => 'Adjustment recorded. It is settled with the next payout.']);
        });
    }

    public function adjustments(Request $request, Affiliate $affiliate): JsonResponse
    {
        $this->authorize($request, 'view');

        return response()->json(['data' => AffiliateAdjustment::query()->where('affiliate_id', $affiliate->id)->orderByDesc('id')->limit(100)->get()
            ->map(fn (AffiliateAdjustment $a) => ['amount_minor' => (int) $a->amount_minor, 'currency' => $a->currency, 'reason' => $a->reason, 'automatic' => $a->created_by === null, 'settled' => $a->affiliate_payout_id !== null, 'created_at' => $a->created_at])->all()]);
    }

    /** @return array<string, mixed> */
    public static function payoutRules(): array
    {
        return [
            'method' => ['required', Rule::in(['bank_transfer', 'jazzcash', 'easypaisa'])],
            'account_title' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9 \-]+$/'],
            'bank' => ['nullable', 'required_if:method,bank_transfer', 'string', 'max:80'],
        ];
    }

    /** @return array<string, mixed> */
    private function program(AffiliateProgram $program): array
    {
        $version = $program->currentVersion;
        $rules = (array) ($version->rules ?? AffiliateRules::DEFAULTS);

        return [
            'id' => $program->public_id, 'name' => $program->name, 'status' => $program->status, 'accepts_applications' => $program->accepts_applications,
            'version' => $version?->version, 'currency' => $version?->currency, 'terms' => $version->terms ?? '',
            'rules' => [...$rules, 'rate_percent' => ((int) $rules['rate_bp']) / 100],
            'summary' => AffiliateProgramService::summary($rules, (string) ($version->currency ?? 'PKR')),
        ];
    }

    private function authorize(Request $request, string $ability): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(app(AffiliatePolicy::class)->{$ability}($user), 403);

        return $user;
    }

    /** @param \Closure(): JsonResponse $action */
    private function attempt(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (AffiliateRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'code' => $refused->errorCode], 422);
        }
    }
}
