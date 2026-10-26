<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Http\Controllers;

use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Services\LoyaltyProgramService;
use App\Domain\Loyalty\Services\LoyaltyRedemption;
use App\Domain\Loyalty\Services\LoyaltyRefused;
use App\Domain\Orders\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase P2: the signed-in customer's own points — balance, tier, history —
 * and turning points into store credit.
 */
final class CustomerLoyaltyController
{
    public function __construct(private readonly LoyaltyProgramService $programs) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $program = $this->programs->current();

        return response()->json(['data' => [
            'program' => self::publicProgram($program, $this->programs->running($program)),
            ...LoyaltyAdminController::summary($customer, $program, staff: false),
        ]]);
    }

    public function redeem(Request $request, LoyaltyRedemption $redemption): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ]);
        /** @var Customer $customer */
        $customer = $request->user();
        try {
            $credit = $redemption->redeem($customer, (int) $data['points'], $data['idempotency_key']);
        } catch (LoyaltyRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason, 'errors' => ['points' => [$e->getMessage()]]], 422);
        }
        $program = $this->programs->current();

        return response()->json(['data' => [
            'credited_minor' => $credit->amount_minor,
            'currency' => $credit->currency,
            'program' => self::publicProgram($program, $this->programs->running($program)),
            ...LoyaltyAdminController::summary($customer, $program, staff: false),
        ]], 201);
    }

    /**
     * What shoppers may know of the program: how points are earned and used.
     * Null when the store has none; `earning` is false while it is off.
     *
     * @return array<string, mixed>|null
     */
    public static function publicProgram(?LoyaltyProgram $program, bool $running): ?array
    {
        if ($program === null) {
            return null;
        }

        return [
            'earning' => $running,
            ...$program->only(['currency', 'earn_points', 'earn_spend_minor', 'redeem_points', 'redeem_value_minor', 'min_redeem_points', 'hold_days', 'expiry_days', 'birthday_points', 'repeat_every', 'repeat_points']),
            'tiers' => $program->sortedTiers(),
        ];
    }
}
