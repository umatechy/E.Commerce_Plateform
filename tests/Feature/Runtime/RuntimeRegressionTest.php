<?php

declare(strict_types=1);

namespace Tests\Feature\Runtime;

use App\Domain\Catalog\Models\Product;
use App\Domain\DeveloperPlatform\Models\ApplicationStatus;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Services\ApiKeyService;
use App\Domain\Events\Jobs\ConsumeOutboxEventJob;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Seo\Exceptions\InvalidRedirectException;
use App\Domain\Seo\Services\RedirectService;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression tests for defects found on the platform's first real run
 * against MySQL 8 (see docs/checkpoints/checkpoint-b21.md). Each test
 * failed, or would have failed in production, before its fix.
 */
final class RuntimeRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_public_id_model_gets_a_ulid_at_creation(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $store->public_id);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $user->public_id);
        $this->assertNotSame($store->public_id, $user->public_id);
    }

    public function test_a_platform_setting_change_records_a_platform_scope_outbox_event(): void
    {
        app(TenantContext::class)->resolveToPlatform();

        app(ConfigService::class)->set('platform.maintenance_mode', true, SettingScope::Platform, null);

        $event = OutboxEvent::query()->withoutTenantScope()->where('event_type', 'setting.changed')->sole();
        $this->assertNull($event->store_id);

        // Consumed in platform context, with no store to route webhooks to.
        $context = new TenantContext();
        (new ConsumeOutboxEventJob($event->id))->handle(
            $context,
            app(\App\Domain\Notifications\Services\NotificationEventRouter::class),
            app(\App\Domain\DeveloperPlatform\Services\WebhookEventRouter::class),
        );

        $this->assertTrue($context->isPlatform());
        $this->assertSame('published', $event->fresh()->status->value);
    }

    public function test_the_same_setting_can_change_twice_within_one_second(): void
    {
        Carbon::setTestNow('2026-09-29 12:00:00');
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        app(ConfigService::class)->set('store.timezone', 'Asia/Karachi', SettingScope::Store, null);
        app(ConfigService::class)->set('store.timezone', 'UTC', SettingScope::Store, null);

        $this->assertSame('UTC', app(ConfigService::class)->get('store.timezone'));
        $this->assertSame(2, OutboxEvent::query()->where('event_type', 'setting.changed')->count());
    }

    public function test_a_second_low_stock_adjustment_within_the_hour_still_succeeds(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 10, 'reorder_point' => 8]);
        $service = app(InventoryService::class);

        $service->adjustStock($inventory, -3, 'Damaged', actorId: $this->actorId(), idempotencyKey: 'low-1');
        $service->adjustStock($inventory->fresh(), -1, 'Damaged', actorId: $this->actorId(), idempotencyKey: 'low-2');

        $this->assertSame(6, $inventory->fresh()->on_hand);
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'inventory.low_stock_detected')->count());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function externalRedirectDestinations(): iterable
    {
        yield 'absolute url' => ['https://evil.example/phishing'];
        yield 'protocol relative' => ['//evil.example/phishing'];
        yield 'backslash disguised' => ['/\\evil.example/phishing'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
    }

    #[DataProvider('externalRedirectDestinations')]
    public function test_redirects_never_point_off_site(string $destination): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $this->expectException(InvalidRedirectException::class);

        app(RedirectService::class)->create('/old-page', $destination);
    }

    public function test_developer_api_show_returns_the_stores_actual_product(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $application = DeveloperApplication::factory()->for($store)->create(['status' => ApplicationStatus::Active]);
        $key = app(ApiKeyService::class)->issue($application, ['products:read'])['plaintext'];
        $product = Product::factory()->for($store)->create(['name' => 'Blue Shirt']);

        $response = $this->withHeader('Authorization', "Bearer {$key}")->getJson("/api/dev/v1/products/{$product->id}");

        $response->assertOk()->assertJsonPath('data.name', 'Blue Shirt');
    }
}
