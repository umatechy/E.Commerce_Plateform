<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Services;

use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyEntryType;
use App\Domain\Loyalty\Models\LoyaltyOrderAward;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Settings\Services\ConfigService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase P2: points follow the store's orders, for the active store.
 *
 * - track: an order of a customer with an account, placed while the program runs,
 *   gets a pending award: points for what the customer pays for products —
 *   after discounts, without delivery, without tax charged on top, and
 *   without what they paid with store credit — with their tier's bonus.
 * - settle: the points are earned once the order is paid and delivered and
 *   the store's hold is over. An order cancelled before that earns nothing.
 * - refunds: a refund takes back the same share of the points, also after
 *   they were earned; if the customer has used them already, what is left
 *   is taken and the rest is written off (the balance is never negative).
 * - repeat purchases: every Nth earning order brings the store's bonus.
 * - birthdays: once a year, to customers who have earned points before.
 * - expiry: if the store set one, a balance lapses after that many days
 *   without the customer earning or using points.
 *
 * Idempotent: it reads the orders as they are and changes only what differs,
 * so running it again changes nothing.
 */
final class LoyaltyEngine
{
    /** Order statuses after delivery (Module 09 lifecycle). */
    private const DELIVERED = ['delivered', 'completed', 'return_requested', 'partially_returned', 'partially_refunded', 'returned', 'refund_pending', 'refunded'];

    /** Earned awards are followed for refunds for this long. */
    private const REFUND_WATCH_DAYS = 365;

    public function __construct(private readonly LoyaltyLedger $ledger, private readonly LoyaltyProgramService $programs) {}

    /** @return array{tracked: int, earned: int, reversed: int, cancelled: int, birthdays: int, expired: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $result = ['tracked' => 0, 'earned' => 0, 'reversed' => 0, 'cancelled' => 0, 'birthdays' => 0, 'expired' => 0];
        $program = $this->programs->current();
        if ($program === null) {
            return $result;
        }
        $running = $this->programs->running($program);

        if ($running) {
            $result['tracked'] = $this->track($program);
        }
        // Awards already made settle and follow refunds even if the program was turned off since.
        LoyaltyOrderAward::query()->where(fn ($q) => $q->where('status', 'pending')
            ->orWhere(fn ($e) => $e->where('status', 'earned')->where('earned_at', '>=', $now->copy()->subDays(self::REFUND_WATCH_DAYS))))
            ->orderBy('id')->chunkById(200, function ($awards) use ($program, $now, &$result) {
                foreach ($awards as $award) {
                    $outcome = DB::transaction(fn () => $this->follow($award->id, $program, $now));
                    if ($outcome !== null) {
                        $result[$outcome]++;
                    }
                }
            });
        if ($running) {
            $result['birthdays'] = $this->birthdays($program, $now);
        }
        $result['expired'] = $this->expire($program, $now);

        return $result;
    }

    /** What an order's points are for. */
    public static function baseOf(Order $order): int
    {
        $tax = $order->prices_include_tax ? 0 : (int) $order->tax_total_minor;

        return max(0, (int) $order->grand_total_minor - (int) $order->shipping_total_minor - $tax - (int) $order->store_credit_minor);
    }

    private function track(LoyaltyProgram $program): int
    {
        $tracked = 0;
        Order::query()->whereNotNull('customer_id')->where('created_at', '>=', $program->started_at)
            ->where('currency', $program->currency)
            ->whereNotIn('status', ['cancelled', 'failed', 'draft'])
            ->whereNotIn('id', LoyaltyOrderAward::query()->select('order_id'))
            ->orderBy('id')->chunkById(200, function ($orders) use ($program, &$tracked) {
                foreach ($orders as $order) {
                    $customer = Customer::query()->find($order->customer_id);
                    // Points are for customers with an account: a guest record could never use them.
                    if ($customer === null || $customer->erased_at !== null || $customer->password === null) {
                        continue;
                    }
                    $lifetime = (int) LoyaltyAccount::query()->where('customer_id', $customer->id)->value('lifetime_points');
                    $tier = $program->tierFor($lifetime);
                    $base = self::baseOf($order);
                    $points = $program->pointsFor($base, (int) ($tier['bonus_percent'] ?? 0));
                    LoyaltyOrderAward::query()->create([
                        'order_id' => $order->id, 'customer_id' => $customer->id,
                        'status' => $points > 0 ? 'pending' : 'cancelled',
                        'base_minor' => $base, 'points' => $points,
                        'snapshot' => [
                            'earn_points' => $program->earn_points, 'earn_spend_minor' => $program->earn_spend_minor,
                            'tier' => $tier['name'] ?? null, 'bonus_percent' => (int) ($tier['bonus_percent'] ?? 0), 'hold_days' => $program->hold_days,
                        ],
                    ]);
                    $tracked++;
                }
            });

        return $tracked;
    }

    private function follow(int $id, LoyaltyProgram $program, Carbon $now): ?string
    {
        $award = LoyaltyOrderAward::query()->lockForUpdate()->find($id);
        $order = $award === null ? null : Order::query()->find($award->order_id);
        $customer = $award === null ? null : Customer::query()->find($award->customer_id);
        if ($award === null || $order === null || $customer === null || ! in_array($award->status, ['pending', 'earned'], true)) {
            return null;
        }
        $status = $order->status->value;
        $cancelled = in_array($status, ['cancelled', 'failed'], true);
        $grand = max(1, (int) $order->grand_total_minor);
        $refunded = $cancelled ? $grand : min($grand, $this->refunded($order));
        $keep = intdiv($award->points * ($grand - $refunded), $grand);

        if ($award->status === 'pending') {
            if ($cancelled || $customer->erased_at !== null || ($keep === 0 && $refunded > 0)) {
                $award->update(['status' => 'cancelled']);

                return 'cancelled';
            }
            $since = $this->deliveredAt($order);
            if ($since === null) {
                return null;
            }
            $holdUntil = $since->copy()->addDays((int) ($award->snapshot['hold_days'] ?? 0));
            if ($award->hold_until === null || ! $award->hold_until->equalTo($holdUntil)) {
                $award->update(['hold_until' => $holdUntil]);
            }
            if ($holdUntil->greaterThan($now)) {
                return null;
            }
            // Refunds before the points were earned simply mean fewer points.
            $this->ledger->move($customer, $keep, LoyaltyEntryType::Earned, "order:{$order->id}:earned", ['type' => 'order', 'id' => $order->id], "Order {$order->order_number}");
            $award->update(['status' => 'earned', 'earned_at' => $now, 'reversed_points' => $award->points - $keep]);
            $this->repeatBonus($program, $award, $customer, $order);

            return 'earned';
        }

        // Earned: a later refund or cancellation takes back its share.
        $due = ($award->points - $keep) - $award->reversed_points;
        if ($due <= 0) {
            return null;
        }
        $this->ledger->move($customer, -$due, LoyaltyEntryType::Reversed, "order:{$order->id}:reversed:".($award->reversed_points + $due), ['type' => 'order', 'id' => $order->id], $cancelled ? "Order {$order->order_number} cancelled" : "Refund on order {$order->order_number}", partial: true);
        $award->update(['reversed_points' => $award->reversed_points + $due]);

        return 'reversed';
    }

    private function repeatBonus(LoyaltyProgram $program, LoyaltyOrderAward $award, Customer $customer, Order $order): void
    {
        if ($program->repeat_every === null || $program->repeat_points <= 0) {
            return;
        }
        $count = LoyaltyOrderAward::query()->where('customer_id', $customer->id)->where('status', 'earned')->count();
        if ($count > 0 && $count % $program->repeat_every === 0) {
            $this->ledger->move($customer, $program->repeat_points, LoyaltyEntryType::RepeatBonus, "order:{$order->id}:repeat", ['type' => 'order', 'id' => $order->id], "Bonus for order number {$count}");
            $award->update(['bonus_points' => $program->repeat_points]);
        }
    }

    private function birthdays(LoyaltyProgram $program, Carbon $now): int
    {
        if ($program->birthday_points <= 0) {
            return 0;
        }
        $today = $now->copy()->setTimezone((string) (app(ConfigService::class)->get('store.timezone') ?: 'UTC'));
        $month = (int) $today->month;
        $days = [(int) $today->day];
        // 29 February birthdays are celebrated on the 28th in other years.
        if ($month === 2 && $today->day === 28 && ! $today->isLeapYear()) {
            $days[] = 29;
        }
        $given = 0;
        Customer::query()->where('birth_month', $month)->whereIn('birth_day', $days)->whereNull('erased_at')
            ->whereIn('id', LoyaltyOrderAward::query()->where('status', 'earned')->select('customer_id'))
            ->orderBy('id')->chunkById(200, function ($customers) use ($program, $today, &$given) {
                foreach ($customers as $customer) {
                    $entry = $this->ledger->move($customer, $program->birthday_points, LoyaltyEntryType::Birthday, "customer:{$customer->id}:birthday:{$today->year}", null, 'Happy birthday!');
                    $given += $entry?->wasRecentlyCreated ? 1 : 0;
                }
            });

        return $given;
    }

    private function expire(LoyaltyProgram $program, Carbon $now): int
    {
        if ($program->expiry_days === null) {
            return 0;
        }
        $cutoff = $now->copy()->subDays($program->expiry_days);
        $expired = 0;
        LoyaltyAccount::query()->where('balance', '>', 0)
            ->where(fn ($q) => $q->where('last_activity_at', '<', $cutoff)->orWhere(fn ($n) => $n->whereNull('last_activity_at')->where('created_at', '<', $cutoff)))
            ->orderBy('id')->chunkById(200, function ($accounts) use (&$expired) {
                foreach ($accounts as $account) {
                    $customer = Customer::query()->find($account->customer_id);
                    if ($customer === null) {
                        continue;
                    }
                    $since = ($account->last_activity_at ?? $account->created_at)?->getTimestamp();
                    $this->ledger->move($customer, -$account->balance, LoyaltyEntryType::Expired, "account:{$account->id}:expired:{$since}", null, 'Expired after a period without activity', partial: true);
                    $expired++;
                }
            });

        return $expired;
    }

    /** Money given back for the order: payment refunds and refunds of returns as store credit. */
    private function refunded(Order $order): int
    {
        $payments = (int) DB::table('payment_transactions')
            ->whereIn('payment_id', DB::table('payments')->where('order_id', $order->id)->select('id'))
            ->whereIn('type', ['refund', 'partial_refund'])->where('status', 'succeeded')->sum('amount_minor');
        $credit = (int) DB::table('return_requests')->where('order_id', $order->id)->sum('refunded_credit_minor');

        return $payments + $credit;
    }

    /**
     * When the paid order was delivered, or null while it is not both. An
     * order is delivered when all of it went out and every shipment arrived
     * (Module 13: the shipments carry delivery), or when staff moved the
     * order itself to delivered.
     */
    private function deliveredAt(Order $order): ?Carbon
    {
        if (! in_array($order->payment_status->value, ['paid', 'partially_refunded'], true)) {
            return null;
        }
        if ($order->fulfillment_status->value === 'fulfilled') {
            $shipments = DB::table('shipments')->where('order_id', $order->id)->where('status', '!=', 'cancelled');
            if (! (clone $shipments)->where('status', '!=', 'delivered')->exists() && ($last = (clone $shipments)->max('delivered_at')) !== null) {
                return Carbon::parse($last, 'UTC');
            }
        }
        if (! in_array($order->status->value, self::DELIVERED, true)) {
            return null;
        }
        $delivered = DB::table('order_timeline_events')->where('order_id', $order->id)->where('to_status', 'delivered')->min('created_at');

        return Carbon::parse($delivered ?? $order->completed_at ?? $order->updated_at);
    }
}
