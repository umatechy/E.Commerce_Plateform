<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Customers\Policies\CustomerPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyEntry;
use App\Domain\Loyalty\Models\LoyaltyEntryType;
use App\Domain\Loyalty\Models\LoyaltyOrderAward;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Policies\LoyaltyPolicy;
use App\Domain\Loyalty\Services\InsufficientPoints;
use App\Domain\Loyalty\Services\LoyaltyLedger;
use App\Domain\Loyalty\Services\LoyaltyProgramService;
use App\Domain\Loyalty\Services\LoyaltyRefused;
use App\Domain\Loyalty\Support\LoyaltyRules;
use App\Domain\Orders\Models\Customer;
use App\Domain\Settings\Services\ConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Phase P2 — Marketing → Loyalty points: the program's rules, turning it on
 * and off, the members, and one customer's points on the customer page.
 *
 * loyalty.view to look, loyalty.manage to run it, loyalty.adjust to give or
 * take points by hand (with a reason and step-up on the route). Everything
 * is the active store's only: route bindings and queries are tenant-scoped.
 */
final class LoyaltyAdminController
{
    public function __construct(private readonly LoyaltyProgramService $programs, private readonly LoyaltyLedger $ledger) {}

    public function overview(Request $request): JsonResponse
    {
        $user = $this->authorize($request, 'view');
        $program = $this->programs->current();
        $since = now()->subDays(30);
        $policy = app(LoyaltyPolicy::class);
        $outstanding = (int) LoyaltyAccount::query()->sum('balance');
        $sum = fn (array $types) => (int) LoyaltyEntry::query()->whereIn('type', array_map(fn (LoyaltyEntryType $t) => $t->value, $types))->where('created_at', '>=', $since)->sum('points');

        return response()->json(['data' => [
            'included' => $this->programs->included(),
            'running' => $this->programs->running($program),
            'can_manage' => $policy->manage($user),
            'can_adjust' => $policy->adjust($user),
            'program' => $program === null ? null : $this->program($program),
            'currency' => $program->currency ?? strtoupper((string) app(ConfigService::class)->get('store.default_currency')),
            'max_tiers' => LoyaltyRules::MAX_TIERS,
            'stats' => [
                'members' => LoyaltyAccount::query()->where('lifetime_points', '>', 0)->count(),
                'outstanding_points' => $outstanding,
                // What the points everyone holds would turn into: what the store owes if all were used.
                'outstanding_value_minor' => $program?->valueOf($outstanding) ?? 0,
                'pending_points' => (int) LoyaltyOrderAward::query()->where('status', 'pending')->sum('points'),
                'earned_30d' => $sum([LoyaltyEntryType::Earned, LoyaltyEntryType::RepeatBonus, LoyaltyEntryType::Birthday]),
                'redeemed_30d' => -$sum([LoyaltyEntryType::Redeemed]),
                'redeemed_value_30d_minor' => (int) \App\Domain\StoreCredit\Models\StoreCreditEntry::query()->where('type', 'loyalty_points')->where('created_at', '>=', $since)->sum('amount_minor'),
            ],
        ]]);
    }

    public function saveProgram(Request $request): JsonResponse
    {
        $user = $this->authorize($request, 'manage');
        $validator = Validator::make($request->all(), LoyaltyRules::validation());
        $validator->after(fn ($v) => LoyaltyRules::after($v));
        $program = $this->programs->save($user, LoyaltyRules::fromInput($validator->validate()));

        return response()->json(['data' => $this->program($program), 'message' => 'Saved. New rates apply to orders from now on; orders already placed keep theirs.']);
    }

    public function enable(Request $request): JsonResponse
    {
        $user = $this->authorize($request, 'manage');
        try {
            $program = $this->programs->enable($user);
        } catch (LoyaltyRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
        }

        return response()->json(['data' => $this->program($program), 'message' => 'Loyalty points are on. Customers earn points on orders from now on.']);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $this->authorize($request, 'manage');
        $program = $this->programs->disable($user);

        return response()->json(['data' => $program === null ? null : $this->program($program), 'message' => 'Turned off. New orders earn no points; customers keep and can still use theirs.']);
    }

    public function members(Request $request): JsonResponse
    {
        $this->authorize($request, 'view');
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $program = $this->programs->current();
        $page = LoyaltyAccount::query()->with('customer:id,public_id,name,email,erased_at')
            ->when($data['q'] ?? null, fn ($q, $s) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->where(fn ($q) => $q->where('balance', '>', 0)->orWhere('lifetime_points', '>', 0))
            ->orderByDesc('lifetime_points')->orderBy('id')->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (LoyaltyAccount $a) => [
                'customer' => ['id' => $a->customer?->public_id, 'name' => $a->customer?->name, 'email' => $a->customer?->erased_at === null ? $a->customer?->email : null],
                'balance' => $a->balance,
                'lifetime_points' => $a->lifetime_points,
                'tier' => $program?->tierFor($a->lifetime_points)['name'] ?? null,
                'last_activity_at' => $a->last_activity_at?->toIso8601String(),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function customer(Request $request, Customer $customer): JsonResponse
    {
        $user = $request->user();
        abort_unless(app(CustomerPolicy::class)->view($user), 403);

        return response()->json(['data' => [
            ...self::summary($customer, $this->programs->current(), staff: true),
            'can_adjust' => app(LoyaltyPolicy::class)->adjust($user) && $customer->erased_at === null && $this->programs->current() !== null,
        ]]);
    }

    public function adjust(Request $request, Customer $customer, AuditLogger $audit): JsonResponse
    {
        $user = $this->authorize($request, 'adjust');
        $data = $request->validate([
            'points' => ['required', 'integer', 'not_in:0', 'min:-1000000000', 'max:1000000000'],
            'note' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ]);
        $program = $this->programs->current();
        if ($program === null) {
            return response()->json(['message' => 'Set up the loyalty program first.', 'code' => 'no_program'], 409);
        }
        if ($customer->erased_at !== null) {
            return response()->json(['message' => 'This customer\'s personal data was erased. Their points have ended.', 'code' => 'erased'], 409);
        }
        try {
            $entry = $this->ledger->move($customer, (int) $data['points'], LoyaltyEntryType::Adjustment, "adjust:{$customer->id}:".$data['idempotency_key'], null, $data['note'], $user->id);
        } catch (InsufficientPoints $e) {
            return response()->json(['message' => "The customer has only {$e->balance} points.", 'code' => 'insufficient_points'], 422);
        }
        if ($entry?->wasRecentlyCreated) {
            $audit->record('loyalty.adjusted', ['points' => (int) $data['points'], 'balance_after' => $entry->balance_after, 'reason' => $data['note']], $customer, actor: $user);
        }

        return response()->json(['data' => [...self::summary($customer, $program, staff: true), 'can_adjust' => true]], 201);
    }

    /**
     * A customer's points, for staff (the customer page) and for the customer (their account).
     *
     * @return array<string, mixed>
     */
    public static function summary(Customer $customer, ?LoyaltyProgram $program, bool $staff): array
    {
        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->first();
        $balance = (int) ($account->balance ?? 0);
        $lifetime = (int) ($account->lifetime_points ?? 0);
        $next = $program?->nextTier($lifetime);

        return [
            'balance' => $balance,
            'value_minor' => $program?->valueOf($balance) ?? 0,
            'currency' => $program->currency ?? null,
            'lifetime_points' => $lifetime,
            'tier' => $program?->tierFor($lifetime),
            'next_tier' => $next === null ? null : [...$next, 'points_to_go' => $next['min_points'] - $lifetime],
            'pending_points' => (int) LoyaltyOrderAward::query()->where('customer_id', $customer->id)->where('status', 'pending')->sum('points'),
            'expires_at' => $program?->expiry_days !== null && $balance > 0 && $account?->last_activity_at !== null
                ? $account->last_activity_at->copy()->addDays($program->expiry_days)->toIso8601String() : null,
            'entries' => LoyaltyEntry::query()->where('customer_id', $customer->id)->with($staff ? ['actor'] : [])->orderByDesc('id')->limit(50)->get()
                ->map(fn (LoyaltyEntry $e) => [
                    'id' => $e->public_id,
                    'type' => $e->type->value,
                    'points' => $e->points,
                    'balance_after' => $e->balance_after,
                    'note' => $e->note,
                    'created_at' => $e->created_at->toIso8601String(),
                    ...($staff ? ['by' => $e->actor?->name] : []),
                ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function program(LoyaltyProgram $program): array
    {
        return [
            ...$program->only(['is_enabled', 'currency', 'earn_points', 'earn_spend_minor', 'redeem_points', 'redeem_value_minor', 'min_redeem_points', 'hold_days', 'expiry_days', 'birthday_points', 'repeat_every', 'repeat_points']),
            'tiers' => $program->sortedTiers(),
            'started_at' => $program->started_at?->toIso8601String(),
            'return_rate' => round(LoyaltyRules::returnRate($program), 6),
        ];
    }

    private function authorize(Request $request, string $ability): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(app(LoyaltyPolicy::class)->{$ability}($user), 403);

        return $user;
    }
}
