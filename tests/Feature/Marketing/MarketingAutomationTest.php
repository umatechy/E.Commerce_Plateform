<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Orders\Models\Customer;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Promotions\Models\Coupon;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Returns\BuildsReturns;
use Tests\TestCase;

/**
 * Phase P4b — marketing automation (Business, `marketing.advanced`; Module 15
 * §29–44): welcome, post-purchase, win-back and birthday emails that go out
 * by themselves — once per occasion, only with consent, not within a day of
 * another marketing email, and only in stores whose package includes them.
 */
final class MarketingAutomationTest extends TestCase
{
    use BuildsReturns, RefreshDatabase;

    private function include(): void
    {
        Subscription::query()->withoutGlobalScopes()->where('store_id', $this->store->id)->sole()->package
            ->entitlements()->create(['key' => 'marketing.advanced', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function turnOn(string $kind, int $delay, array $more = []): void
    {
        $this->asStaff();
        $this->actingAs($this->owner)->putJson("/api/v1/marketing/automations/{$kind}", [
            'is_enabled' => true, 'delay_days' => $delay, 'subject' => "Subject {$kind} {{order.number}}", 'body' => 'Hi {{customer.name}} from {{store.name}}', ...$more,
        ])->assertOk();
    }

    private function member(string $email, bool $consent = true): Customer
    {
        return Customer::factory()->for($this->store)->create(['email' => $email, 'password' => Hash::make('correct-horse-99'), 'marketing_email_opt_in' => $consent]);
    }

    private function runAutomations(): void
    {
        $this->asStaff();
        Artisan::call('marketing:automations');
        app(TenantContext::class)->resolveToStore($this->store->id);
    }

    /** @return list<string> subjects sent to the customer, oldest first */
    private function sentTo(Customer $customer): array
    {
        return NotificationMessage::query()->where('recipient_id', $customer->id)->where('message_type', 'marketing')->orderBy('id')->pluck('subject')->all();
    }

    public function test_the_package_and_permission_decide_and_the_settings_are_checked(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v1/marketing/automations')->assertOk()->assertJsonPath('data.included', false)
            ->assertJsonPath('data.automations.0.kind', 'welcome')->assertJsonPath('data.automations.0.is_enabled', false);
        $body = ['is_enabled' => true, 'delay_days' => 0, 'subject' => 'Welcome', 'body' => 'Hi'];
        $this->actingAs($this->owner)->putJson('/api/v1/marketing/automations/welcome', $body)->assertForbidden()->assertJsonPath('code', 'feature_not_entitled');
        $this->include();
        $this->actingAs($this->owner)->putJson('/api/v1/marketing/automations/win_back', [...$body, 'delay_days' => 5])->assertUnprocessable()->assertJsonValidationErrors('delay_days');
        $this->actingAs($this->owner)->putJson('/api/v1/marketing/automations/nothing', $body)->assertNotFound();

        $other = Store::factory()->create();
        $foreign = Coupon::query()->withoutGlobalScopes()->create(['store_id' => $other->id, 'promotion_id' => Promotion::factory()->for($other)->create()->id, 'code' => 'THEIRS', 'code_normalized' => 'THEIRS', 'is_active' => true]);
        $this->actingAs($this->owner)->putJson('/api/v1/marketing/automations/welcome', [...$body, 'coupon_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('coupon_id');
        $this->actingAs($this->owner)->putJson('/api/v1/marketing/automations/welcome', $body)->assertOk();

        $viewer = $this->staffWith(['marketing.view']);
        $this->asStaff();
        $this->actingAs($viewer)->getJson('/api/v1/marketing/automations')->assertOk()->assertJsonPath('data.can_manage', false)->assertJsonPath('data.automations.0.is_enabled', true);
        $this->actingAs($viewer)->putJson('/api/v1/marketing/automations/welcome', $body)->assertForbidden();
    }

    public function test_welcome_goes_once_to_new_accounts_with_consent_only(): void
    {
        $before = $this->member('before@example.com');
        $this->include();
        $this->travel(1)->minutes();
        $this->turnOn('welcome', 0);
        $this->travel(1)->minutes();
        $ayesha = $this->member('ayesha@example.com');
        $noConsent = $this->member('quiet@example.com', consent: false);
        $guest = Customer::factory()->for($this->store)->create(['email' => 'guest@example.com', 'password' => null, 'marketing_email_opt_in' => true]);

        $this->runAutomations();
        $this->runAutomations();
        $this->assertSame(['Subject welcome '], $this->sentTo($ayesha));
        $this->assertSame([], $this->sentTo($before), 'customers from before it was turned on are not written to');
        $this->assertSame([], $this->sentTo($noConsent));
        $this->assertSame([], $this->sentTo($guest));
        $message = NotificationMessage::query()->where('recipient_id', $ayesha->id)->sole();
        $this->assertStringContainsString($this->store->name, $message->body);
        $this->assertStringContainsString('Unsubscribe:', $message->body);
    }

    public function test_post_purchase_waits_for_delivery_plus_the_delay_and_win_back_and_birthday_follow_their_occasions(): void
    {
        $this->include();
        $this->turnOn('post_purchase', 3);
        $this->travel(1)->minutes();
        $ayesha = $this->member('ayesha@example.com');
        $order = $this->deliveredOrder(1, $ayesha);

        $this->runAutomations();
        $this->assertSame([], $this->sentTo($ayesha));
        $this->travel(3)->days();
        $this->runAutomations();
        $this->runAutomations();
        $this->assertSame(["Subject post_purchase {$order->order_number}"], $this->sentTo($ayesha));

        // Win-back: 14 days after the last order. Not within a day of the thank-you, so the next run sends it.
        $this->turnOn('win_back', 14);
        $this->travel(11)->days();
        $this->travel(5)->minutes();
        $this->runAutomations();
        $this->assertCount(2, $this->sentTo($ayesha));
        $this->assertSame('Subject win_back ', $this->sentTo($ayesha)[1]);

        // Birthday: once a year; a second automation due the same day waits a day.
        $ayesha->forceFill(['birth_month' => (int) now()->month, 'birth_day' => (int) now()->day])->save();
        $this->turnOn('birthday', 0);
        $this->runAutomations();
        $this->assertCount(2, $this->sentTo($ayesha), 'not within a day of the win-back email');
        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $ayesha->forceFill(['birth_month' => (int) now()->month, 'birth_day' => (int) now()->day])->save();
        $this->runAutomations();
        $this->runAutomations();
        $this->assertSame('Subject birthday ', $this->sentTo($ayesha)[2]);
        $this->assertCount(3, $this->sentTo($ayesha));

        // The package no longer includes it: nothing goes out.
        \App\Domain\Packages\Models\PackageEntitlement::query()->where('key', 'marketing.advanced')->delete();
        \Illuminate\Support\Facades\Cache::flush();
        $bilal = $this->member('bilal@example.com');
        $this->turnOffAndOnWelcomeDirectly();
        $this->travel(2)->days();
        $this->runAutomations();
        $this->assertSame([], $this->sentTo($bilal));
    }

    private function turnOffAndOnWelcomeDirectly(): void
    {
        \App\Domain\Marketing\Models\MarketingAutomation::query()->create(['kind' => 'welcome', 'is_enabled' => true, 'delay_days' => 0, 'subject' => 'W', 'body' => 'B', 'enabled_at' => now()->subDay()]);
    }
}
