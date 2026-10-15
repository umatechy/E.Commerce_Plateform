<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Catalog\Models\Product;
use App\Domain\DeveloperPlatform\Jobs\DispatchWebhookJob;
use App\Domain\DeveloperPlatform\Models\ApplicationStatus;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Models\WebhookSubscription;
use App\Domain\DeveloperPlatform\Services\ApiKeyService;
use App\Domain\DeveloperPlatform\Services\WebhookEventRouter;
use App\Domain\DeveloperPlatform\Support\DeveloperApiSpec;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase B53 — API quality (gap G13): one error shape (SRS API-010; Master
 * Prompt §15), the OpenAPI description and contract tests (API-012;
 * Module 31 §51, §65), the webhook event catalog (§35), Idempotency-Key
 * (API-007; §42) and deterministic pages (API-008).
 */
final class ApiQualityTest extends TestCase
{
    use RefreshDatabase;

    private function keyFor(Store $store, array $scopes): string
    {
        app(TenantContext::class)->resolveToStore($store->id);
        $application = DeveloperApplication::factory()->for($store)->create(['status' => ApplicationStatus::Active]);

        return app(ApiKeyService::class)->issue($application, $scopes)['plaintext'];
    }

    public function test_every_api_error_has_code_message_details_and_the_request_id(): void
    {
        $unknown = $this->getJson('/api/v1/nowhere')->assertNotFound();
        $this->assertSame('not_found', $unknown->json('code'));
        $this->assertSame($unknown->headers->get('X-Request-Id'), $unknown->json('requestId'));
        $this->assertSame([], $unknown->json('details'));

        $this->getJson('/api/v1/orders')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

        $invalid = $this->postJson('/api/v1/auth/password/forgot', [])->assertStatus(422);
        $this->assertSame('validation_failed', $invalid->json('code'));
        $this->assertArrayHasKey('email', $invalid->json('details.fields'));
        $this->assertArrayHasKey('email', $invalid->json('errors'), 'the admin and storefront apps still read `errors`');

        $store = Store::factory()->create();
        $key = $this->keyFor($store, ['products:read']);
        $this->getJson('/api/dev/v1/customers', ['Authorization' => "Bearer {$key}"])->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope')->assertJsonStructure(['code', 'message', 'details', 'requestId']);
    }

    public function test_a_server_error_never_shows_its_message_trace_or_sql(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('/api/v1/__b53_boom', fn () => throw new \RuntimeException('SQLSTATE[42S02]: secret table users_private at /var/www/app.php'));

        $response = $this->getJson('/api/v1/__b53_boom')->assertStatus(500);
        $this->assertSame('server_error', $response->json('code'));
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('/var/www', $response->getContent());
        $this->assertArrayNotHasKey('trace', $response->json());
        $this->assertNotNull($response->json('requestId'));
    }

    public function test_the_openapi_description_covers_every_route_is_committed_and_valid_in_shape(): void
    {
        $spec = DeveloperApiSpec::build(); // throws for an undocumented route
        $this->assertSame('3.1.0', $spec['openapi']);
        $operations = collect($spec['paths'])->flatMap(fn (array $ops, string $path) => array_map(fn ($m) => strtoupper($m).' '.$path, array_keys($ops)))->sort()->values()->all();
        $this->assertSame(collect([...array_keys(DeveloperApiSpec::ENDPOINTS), ...array_keys(DeveloperApiSpec::APP_ENDPOINTS)])->sort()->values()->all(), $operations);
        foreach ($spec['paths'] as $path => $ops) {
            foreach ($ops as $method => $op) {
                if (isset(DeveloperApiSpec::APP_ENDPOINTS[strtoupper($method).' '.$path])) {
                    continue; // B56: the marketplace connection calls have no scope
                }
                $this->assertArrayHasKey('x-scope', $op);
                $this->assertArrayHasKey('401', $op['responses']);
                $this->assertArrayHasKey('429', $op['responses']);
            }
        }
        $this->assertSame(array_keys(\App\Domain\DeveloperPlatform\Support\WebhookEventCatalog::EVENTS), collect(array_keys($spec['webhooks']))->sort()->values()->sortBy(fn ($e) => array_search($e, array_keys(\App\Domain\DeveloperPlatform\Support\WebhookEventCatalog::EVENTS), true))->values()->all());

        $this->artisan('api:openapi', ['--check' => true])->assertSuccessful();
        $this->getJson('/api/dev/v1/openapi.json')->assertOk()->assertJsonPath('info.version', 'v1');
    }

    public function test_every_developer_endpoint_answers_exactly_the_documented_fields(): void
    {
        $store = Store::factory()->create();
        $key = $this->keyFor($store, ['products:read', 'orders:read', 'customers:read', 'inventory:read']);
        $product = Product::factory()->for($store)->create();
        Product::factory()->for($store)->count(2)->create();
        $customer = Customer::factory()->for($store)->create();
        $order = Order::factory()->for($store)->create();
        Inventory::factory()->for($store)->for(Warehouse::query()->where('store_id', $store->id)->first() ?? Warehouse::factory()->for($store)->create())->create(['product_id' => $product->id]);
        $ids = ['product' => $product->id, 'order' => $order->id, 'customer' => $customer->id];

        foreach (DeveloperApiSpec::ENDPOINTS as $endpoint => [$summary, , , $schema, $list]) {
            [, $path] = explode(' ', $endpoint);
            $uri = '/api/dev/v1'.preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) $ids[$m[1]], $path);
            $response = $this->getJson($uri, ['Authorization' => "Bearer {$key}"])->assertOk();
            $rows = $list ? $response->json('data') : [$response->json('data')];
            $this->assertNotEmpty($rows, "{$endpoint} returned nothing to check");
            if ($list) {
                $this->assertIsArray($response->json('meta'), "{$endpoint} has page metadata");
            }
            foreach ($rows as $row) {
                $this->assertSame(array_keys(DeveloperApiSpec::SCHEMAS[$schema]), array_keys($row), "{$endpoint}: fields differ from the OpenAPI schema {$schema}");
                foreach (DeveloperApiSpec::SCHEMAS[$schema] as $field => [$type, , , $nullable]) {
                    $value = $row[$field];
                    $ok = ($nullable && $value === null) || ($type === 'integer' ? is_int($value) : is_string($value));
                    $this->assertTrue($ok, "{$endpoint}: {$field} is not a {$type}");
                }
            }
        }

        // Pages are in a fixed order (API-008): two pages never overlap.
        $first = $this->getJson('/api/dev/v1/products?per_page=2', ['Authorization' => "Bearer {$key}"])->json('data.*.id');
        $second = $this->getJson('/api/dev/v1/products?per_page=2&page=2', ['Authorization' => "Bearer {$key}"])->json('data.*.id');
        $this->assertSame([], array_intersect($first, $second));
        $this->assertSame($first, collect($first)->sort()->values()->all());
    }

    public function test_webhooks_offer_and_send_only_catalog_events_with_their_documented_fields(): void
    {
        $store = Store::factory()->create();
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($store->id);
        $application = DeveloperApplication::factory()->for($store)->create(['status' => ApplicationStatus::Active]);

        $this->actingAs($owner)->postJson("/api/v1/developer/applications/{$application->public_id}/webhooks", ['url' => 'https://example.com/hook', 'events' => ['order.created', 'setting.changed']])
            ->assertStatus(422)->assertJsonValidationErrors('events.1');

        $subscription = WebhookSubscription::query()->create(['developer_application_id' => $application->id, 'store_id' => $store->id, 'url' => 'https://example.com/hook',
            'signing_secret' => str_repeat('s', 48), 'subscribed_events' => ['order.created', 'backup.requested'], 'status' => 'active']);
        Bus::fake([DispatchWebhookJob::class]);
        $router = app(WebhookEventRouter::class);
        $router->route('backup.requested', $store->id, ['backup_id' => 7], 'k1'); // subscribed before B53, but not in the catalog
        $router->route('order.created', $store->id, ['order_id' => 9, 'order_number' => 'ORD-9', 'grand_total_minor' => 100, 'internal_note' => 'x'], 'k2');

        Bus::assertDispatchedTimes(DispatchWebhookJob::class, 1);
        Bus::assertDispatched(DispatchWebhookJob::class, fn (DispatchWebhookJob $job) => $job->eventType === 'order.created'
            && $job->payload === ['order_id' => 9, 'order_number' => 'ORD-9', 'grand_total_minor' => 100]);
        $this->assertNotNull($subscription->id);
    }

    public function test_an_idempotency_key_replays_the_first_answer_and_refuses_a_different_request(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        $package = \App\Domain\Packages\Models\Package::factory()->create();
        foreach (['orders.basic', 'payment.cod', 'products.basic'] as $feature) {
            $package->entitlements()->create(['key' => $feature, 'type' => \App\Domain\Packages\Models\EntitlementType::Feature, 'boolean_value' => true]);
        }
        \App\Domain\Packages\Models\Subscription::factory()->for($store)->for($package)->create(['status' => \App\Domain\Packages\Models\SubscriptionStatus::Active]);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($store->id);
        $product = Product::factory()->for($store)->create(['status' => 'active', 'price_minor' => 50000]);
        $warehouse = Warehouse::query()->where('store_id', $store->id)->first() ?? Warehouse::factory()->for($store)->create(['is_default' => true]);
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(\App\Domain\Inventory\Services\InventoryService::class)->setOpeningStock($inventory, 20, 'Init', actorId: $owner->id, idempotencyKey: 'open-b53');
        $body = ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'guest_name' => 'Ayesha', 'guest_email' => 'a@example.com', 'payment_method' => 'cod', 'source' => 'admin', 'idempotency_key' => 'order-body-1'];

        $first = $this->actingAs($owner)->postJson('/api/v1/orders', $body, ['Idempotency-Key' => 'order-create-0001'])->assertCreated();
        $again = $this->actingAs($owner)->postJson('/api/v1/orders', $body, ['Idempotency-Key' => 'order-create-0001'])->assertCreated();
        $this->assertSame('true', $again->headers->get('Idempotent-Replayed'));
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, Order::query()->count());

        $this->actingAs($owner)->postJson('/api/v1/orders', [...$body, 'guest_name' => 'Someone else'], ['Idempotency-Key' => 'order-create-0001'])
            ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
        $this->actingAs($owner)->postJson('/api/v1/orders', $body, ['Idempotency-Key' => 'bad key!'])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_invalid');
        $this->actingAs($owner)->postJson('/api/v1/orders', [...$body, 'idempotency_key' => 'order-body-2'], ['Idempotency-Key' => 'order-create-0002'])->assertCreated();
        $this->assertSame(2, Order::query()->count());

        // A server error is not kept: the retry runs for real.
        Route::middleware(['api', 'auth:sanctum', 'idempotent'])->post('/api/v1/__b53_flaky', fn () => response()->json(['message' => 'down'], 503));
        $this->actingAs($owner)->postJson('/api/v1/__b53_flaky', [], ['Idempotency-Key' => 'flaky-key-0001'])->assertStatus(503);
        $this->assertDatabaseMissing('idempotency_keys', ['idempotency_key' => 'flaky-key-0001']);
    }
}
