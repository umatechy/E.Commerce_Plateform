<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\MarketingAutomation;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Promotions\Models\Coupon;
use App\Domain\Seo\Services\SeoResolver;
use App\Domain\Settings\Services\StoreClock;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase P4b (Module 15 §29–44, §92 Business "Automation"): messages that go
 * out by themselves —
 * - welcome: a set number of days after a customer creates an account;
 * - post_purchase: days after an order is delivered (a thank-you, a review
 *   request);
 * - win_back: when a customer's last order is that many days old;
 * - birthday: on the customer's birthday (day and month they gave).
 *
 * Only to customers who agreed to marketing email and are not blocked or
 * archived (Module 10 §31); never twice for the same occasion; not within a
 * day of another marketing email (the campaigns' cool-down, Module 15 §17) —
 * those wait for the next run while the occasion is fresh. An automation
 * counts from when it was turned on: an occasion more than a few days late
 * (or from before) is let go rather than sent stale.
 */
final class MarketingAutomationService
{
    public const FEATURE = 'marketing.advanced';

    /** How late an occasion may still be sent (welcome, post-purchase), and how long a lapse counts as new (win-back). */
    public const LATE_DAYS = 3;

    public const WIN_BACK_WINDOW_DAYS = 30;

    /** @var array<string, array{delay: int, min: int, max: int, subject: string, body: string}> */
    public const DEFAULTS = [
        'welcome' => ['delay' => 0, 'min' => 0, 'max' => 30, 'subject' => 'Welcome to {{store.name}}',
            'body' => "Hi {{customer.name}},\n\nThank you for creating an account with {{store.name}}. Your orders, addresses and wishlist are now in one place.\n\nVisit us: {{shop.link}}"],
        'post_purchase' => ['delay' => 3, 'min' => 1, 'max' => 60, 'subject' => 'How is your order {{order.number}}?',
            'body' => "Hi {{customer.name}},\n\nWe hope you are happy with your order {{order.number}}. If you have a moment, a short review helps other shoppers — and us.\n\n{{shop.link}}"],
        'win_back' => ['delay' => 60, 'min' => 14, 'max' => 365, 'subject' => 'We miss you at {{store.name}}',
            'body' => "Hi {{customer.name}},\n\nIt has been a while. Come and see what is new at {{store.name}}.\n\n{{shop.link}}"],
        'birthday' => ['delay' => 0, 'min' => 0, 'max' => 0, 'subject' => 'Happy birthday, {{customer.name}}!',
            'body' => "Hi {{customer.name}},\n\nEveryone at {{store.name}} wishes you a very happy birthday.\n\n{{shop.link}}"],
    ];

    public function __construct(private readonly NotificationService $notifications, private readonly AuditLogger $audit) {}

    /** @return array<string, MarketingAutomation> every kind: the store's own, or the first wording (not saved) */
    public function all(): array
    {
        $saved = MarketingAutomation::query()->get()->keyBy('kind');
        $out = [];
        foreach (self::DEFAULTS as $kind => $default) {
            $out[$kind] = $saved[$kind] ?? new MarketingAutomation(['kind' => $kind, 'is_enabled' => false, 'delay_days' => $default['delay'], 'subject' => $default['subject'], 'body' => $default['body']]);
        }

        return $out;
    }

    /** @param array{is_enabled: bool, delay_days: int, subject: string, body: string, coupon_id: ?int} $data */
    public function save(User $by, string $kind, array $data): MarketingAutomation
    {
        $automation = MarketingAutomation::query()->firstOrNew(['kind' => $kind]);
        $turningOn = $data['is_enabled'] && ! $automation->is_enabled;
        $automation->fill([...$data, 'updated_by' => $by->id, ...($turningOn ? ['enabled_at' => now()] : [])])->save();
        $this->audit->record('marketing.automation_saved', ['kind' => $kind, 'enabled' => $automation->is_enabled, 'delay_days' => $automation->delay_days], $automation, actor: $by);

        return $automation;
    }

    /** Sends what is due for the active store. @return array<string, int> sent per kind */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $sent = [];
        foreach (MarketingAutomation::query()->where('is_enabled', true)->get() as $automation) {
            $sent[$automation->kind] = 0;
            foreach ($this->due($automation, $now) as [$customerId, $occasion, $extra]) {
                $sent[$automation->kind] += $this->send($automation, (int) $customerId, $occasion, $extra) ? 1 : 0;
            }
        }

        return $sent;
    }

    /**
     * @return iterable<array{0: int, 1: string, 2: array<string, string>}> [customer id, occasion, extra variables]
     */
    private function due(MarketingAutomation $a, Carbon $now): iterable
    {
        $storeId = app(TenantContext::class)->storeId();
        $start = $a->enabled_at ?? $now;
        $until = $now->copy()->subDays($a->delay_days);
        $from = $until->copy()->subDays(self::LATE_DAYS)->max($start);

        switch ($a->kind) {
            case 'welcome':
                foreach (Customer::query()->whereNotNull('password')->whereBetween('created_at', [$from, $until])->pluck('id') as $id) {
                    yield [(int) $id, 'account', []];
                }
                break;
            case 'post_purchase':
                // Delivered: every shipment arrived (Module 13), or staff moved the order to delivered.
                $rows = DB::table('orders')->where('orders.store_id', $storeId)->whereNotNull('orders.customer_id')->where('orders.status', '!=', OrderStatus::Cancelled->value)
                    ->selectRaw('orders.id, orders.customer_id, orders.order_number, COALESCE(
                        (SELECT MIN(e.created_at) FROM order_timeline_events e WHERE e.order_id = orders.id AND e.to_status = ?),
                        (SELECT MAX(s.delivered_at) FROM shipments s WHERE s.order_id = orders.id AND s.status = ?)) as delivered_at', ['delivered', 'delivered'])
                    ->havingRaw('delivered_at BETWEEN ? AND ?', [$from, $until])->get();
                foreach ($rows as $row) {
                    yield [(int) $row->customer_id, "order:{$row->id}", ['order.number' => (string) $row->order_number]];
                }
                break;
            case 'win_back':
                $rows = DB::table('orders')->where('store_id', $storeId)->whereNotNull('customer_id')->where('status', '!=', OrderStatus::Cancelled->value)
                    ->groupBy('customer_id')->selectRaw('customer_id, MAX(id) as last_id, MAX(created_at) as last_at')
                    ->havingRaw('last_at BETWEEN ? AND ?', [$until->copy()->subDays(self::WIN_BACK_WINDOW_DAYS), $until])->get();
                foreach ($rows as $row) {
                    yield [(int) $row->customer_id, "after-order:{$row->last_id}", []];
                }
                break;
            case 'birthday':
                $today = $now->copy()->setTimezone(app(StoreClock::class)->timezone());
                $days = [(int) $today->day];
                if ($today->month === 2 && $today->day === 28 && ! $today->isLeapYear()) {
                    $days[] = 29;
                }
                foreach (Customer::query()->where('birth_month', $today->month)->whereIn('birth_day', $days)->pluck('id') as $id) {
                    yield [(int) $id, (string) $today->year, []];
                }
                break;
        }
    }

    /** @param array<string, string> $extra */
    private function send(MarketingAutomation $a, int $customerId, string $occasion, array $extra): bool
    {
        $customer = Customer::query()->find($customerId);
        if ($customer === null || $customer->erased_at !== null || ! $customer->marketing_email_opt_in || ! $customer->standing()->mayReceiveMarketing() || $customer->email === '') {
            return false;
        }
        if (DB::table('marketing_automation_sends')->where('marketing_automation_id', $a->id)->where('customer_id', $customer->id)->where('occasion', $occasion)->exists()) {
            return false;
        }
        // Not within a day of another marketing email; the occasion is tried again next run.
        if (NotificationMessage::query()->where('recipient_type', RecipientType::Customer)->where('recipient_id', $customer->id)
            ->where('message_type', NotificationMessageType::Marketing)->where('created_at', '>=', now()->subDay())->exists()) {
            return false;
        }

        return DB::transaction(function () use ($a, $customer, $occasion, $extra) {
            $inserted = DB::table('marketing_automation_sends')->insertOrIgnore([
                'store_id' => $a->store_id, 'marketing_automation_id' => $a->id, 'customer_id' => $customer->id, 'occasion' => $occasion, 'created_at' => now(),
            ]);
            if ($inserted === 0) {
                return false;
            }
            $store = Store::query()->findOrFail($a->store_id);
            $coupon = $a->coupon_id === null ? null : Coupon::query()->where('is_active', true)->find($a->coupon_id);
            $message = $this->notifications->send(
                NotificationMessageType::Marketing, NotificationChannel::Email, RecipientType::Customer, $customer->id, $customer->email,
                $a->subject, $a->body.($coupon ? "\n\nYour code: {{coupon.code}}" : '').'{{unsubscribe.line}}',
                [
                    'customer.name' => $customer->name, 'store.name' => $store->name, 'shop.link' => app(SeoResolver::class)->forStoreHome($store)->canonicalUrl,
                    'coupon.code' => $coupon->code ?? '', 'order.number' => '', ...$extra, 'unsubscribe.line' => $this->unsubscribeLine($store, $customer),
                ],
                "automation:{$a->id}:customer:{$customer->id}:{$occasion}",
                "marketing.automation.{$a->kind}",
            );
            DB::table('marketing_automation_sends')->where('marketing_automation_id', $a->id)->where('customer_id', $customer->id)->where('occasion', $occasion)
                ->update(['notification_message_id' => $message?->id]);

            return true;
        });
    }

    private function unsubscribeLine(Store $store, Customer $customer): string
    {
        $signature = \App\Domain\Notifications\Http\Controllers\UnsubscribeController::signatureFor($store, NotificationChannel::Email, $customer->email);

        return "\n\nUnsubscribe: ".url('/api/v1/public/notifications/unsubscribe').'?'.http_build_query(['store' => $store->slug, 'channel' => 'email', 'destination' => $customer->email, 'signature' => $signature]);
    }
}
