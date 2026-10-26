<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\MarketingAutomation;
use App\Domain\Marketing\Policies\MarketingPolicy;
use App\Domain\Marketing\Services\MarketingAutomationService;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Promotions\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase P4b — Marketing → Automations (Business and Premium,
 * `marketing.advanced`). marketing.view to look, marketing.manage to change.
 */
final class AutomationController
{
    public function __construct(private readonly MarketingAutomationService $automations) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(app(MarketingPolicy::class)->viewAny($request->user()), 403);
        $since = now()->subDays(30);
        $sent = \Illuminate\Support\Facades\DB::table('marketing_automation_sends')->join('marketing_automations', 'marketing_automations.id', '=', 'marketing_automation_sends.marketing_automation_id')
            ->where('marketing_automation_sends.store_id', app(\App\Domain\Tenancy\Support\TenantContext::class)->storeId())->where('marketing_automation_sends.created_at', '>=', $since)
            ->groupBy('marketing_automations.kind')->selectRaw('marketing_automations.kind, COUNT(*) as n')->pluck('n', 'kind');

        return response()->json(['data' => [
            'included' => app(EntitlementService::class)->hasFeature(MarketingAutomationService::FEATURE),
            'can_manage' => app(MarketingPolicy::class)->manage($request->user()),
            'automations' => collect($this->automations->all())->map(fn (MarketingAutomation $a, string $kind) => [
                'kind' => $kind, 'is_enabled' => $a->is_enabled, 'delay_days' => $a->delay_days, 'subject' => $a->subject, 'body' => $a->body,
                'coupon_id' => $a->coupon_id, 'enabled_at' => $a->enabled_at?->toIso8601String(), 'sent_30d' => (int) ($sent[$kind] ?? 0),
                'limits' => ['min' => MarketingAutomationService::DEFAULTS[$kind]['min'], 'max' => MarketingAutomationService::DEFAULTS[$kind]['max']],
            ])->values(),
            'coupons' => Coupon::query()->where('is_active', true)->orderBy('code')->limit(500)->get(['id', 'code'])->map(fn (Coupon $c) => ['id' => $c->id, 'code' => $c->code])->all(),
        ]]);
    }

    public function update(Request $request, string $kind): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(app(MarketingPolicy::class)->manage($user), 403);
        abort_unless(in_array($kind, MarketingAutomation::KINDS, true), 404);
        if (! app(EntitlementService::class)->hasFeature(MarketingAutomationService::FEATURE)) {
            return response()->json(['message' => 'Automations are part of the Business and Premium packages.', 'code' => 'feature_not_entitled'], 403);
        }
        $limits = MarketingAutomationService::DEFAULTS[$kind];
        $data = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'delay_days' => ['required', 'integer', "min:{$limits['min']}", "max:{$limits['max']}"],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'coupon_id' => ['nullable', 'integer', Rule::exists('coupons', 'id')->where('store_id', app(\App\Domain\Tenancy\Support\TenantContext::class)->storeId())],
        ]);
        $automation = $this->automations->save($user, $kind, [
            'is_enabled' => (bool) $data['is_enabled'], 'delay_days' => (int) $data['delay_days'], 'subject' => $data['subject'], 'body' => $data['body'], 'coupon_id' => $data['coupon_id'] ?? null,
        ]);

        return response()->json(['data' => ['kind' => $kind, 'is_enabled' => $automation->is_enabled], 'message' => $automation->is_enabled ? 'Saved. It goes out by itself from now on.' : 'Saved. It is off.']);
    }
}
