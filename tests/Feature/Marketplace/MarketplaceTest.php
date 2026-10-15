<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\DeveloperPlatform\Models\ApiScope;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Services\ApiKeyService;
use App\Domain\DeveloperPlatform\Services\ApplicationService;
use App\Domain\DeveloperPlatform\Services\WebhookEventRouter;
use App\Domain\Identity\Models\User;
use App\Domain\Marketplace\Models\AppInstallation;
use App\Domain\Marketplace\Models\MarketplaceApp;
use App\Domain\Marketplace\Support\MarketplaceCatalog;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase B56 (gap G19; Module 27; SRS APP-001 to APP-007): the app
 * registry and its review, store installation with consent, the
 * authorization-code connection, scoped access, settings, updates, the
 * kill switch, uninstalling, and store isolation.
 */
final class MarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://app.example.com/connect';

    private const WEBHOOK = 'https://93.184.215.14/hooks'; // a public address, so no DNS lookup is needed

    private User $writer;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Queue::fake();
        $this->writer = User::factory()->create(['platform_role' => 'platform_admin']);
        $this->reviewer = User::factory()->create(['platform_role' => 'super_admin']);
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($user);
    }

    /** @return array{0: Store, 1: User} */
    private function store(array $features = ['orders.basic', 'integrations.api']): array
    {
        $store = Store::factory()->create(['status' => 'active']);
        $this->entitle($store, $features);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return [$store, $owner];
    }

    private function listing(array $more = []): array
    {
        return [
            'name' => 'Ledger Sync', 'tagline' => 'Send your orders to your accounts.', 'description' => 'Copies orders into your accounting book every hour.',
            'category' => 'accounting', 'publisher_name' => 'Example Apps', 'publisher_website' => 'https://apps.example.com', 'support_email' => 'help@example.com',
            'privacy_url' => 'https://apps.example.com/privacy', 'terms_url' => null, 'ownership' => 'partner', 'trust' => 'reviewed',
            'redirect_uris' => [self::REDIRECT], 'webhook_url' => self::WEBHOOK, 'required_features' => [], ...$more,
        ];
    }

    private function version(string $version = '1.0.0', array $scopes = ['products:read', 'orders:read'], array $more = []): array
    {
        return [
            'version' => $version, 'scopes' => $scopes, 'events' => ['order.created'],
            'config_schema' => [
                ['key' => 'book', 'label' => 'Accounting book', 'type' => 'text', 'required' => true],
                ['key' => 'api_password', 'label' => 'Accounting password', 'type' => 'secret'],
                ['key' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['hourly', 'daily']],
            ],
            'data_use' => 'Reads products and orders to create invoices in your accounting book. Nothing is shared with others; kept 30 days.',
            'release_notes' => 'First version.', ...$more,
        ];
    }

    /** @return array{0: MarketplaceApp, 1: string} the published app and its client secret */
    private function publishedApp(array $scopes = ['products:read', 'orders:read']): array
    {
        $created = $this->as($this->writer)->postJson('/api/v1/super-admin/marketplace/apps', ['slug' => 'ledger-sync', ...$this->listing()])->assertCreated();
        $id = $created->json('data.id');
        $this->as($this->writer)->putJson("/api/v1/super-admin/marketplace/apps/{$id}/version", $this->version('1.0.0', $scopes))->assertOk();
        $this->as($this->writer)->postJson("/api/v1/super-admin/marketplace/apps/{$id}/submit")->assertOk();
        $this->as($this->reviewer)->postJson("/api/v1/super-admin/marketplace/apps/{$id}/publish", ['checks' => array_fill_keys(array_keys(MarketplaceCatalog::REVIEW_CHECKS), true)])->assertOk();

        return [MarketplaceApp::query()->where('public_id', $id)->firstOrFail(), (string) $created->json('data.client_secret')];
    }

    /** @return array{0: string, 1: array<string, mixed>} the code from the redirect and its query */
    private function install(Store $store, User $owner, string $slug = 'ledger-sync'): array
    {
        $url = (string) $this->as($owner)->postJson("/api/v1/apps/{$slug}/install", ['accept_permissions' => true])->assertCreated()->json('data.redirect_url');
        $this->assertStringStartsWith(self::REDIRECT.'?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame($store->public_id, $query['store']);

        return [(string) $query['code'], $query];
    }

    private function exchange(MarketplaceApp $app, string $secret, string $code, string $redirect = self::REDIRECT): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/dev/v1/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $app->client_id, 'client_secret' => $secret, 'redirect_uri' => $redirect]);
    }

    private function api(string $token, string $path): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/dev/v1{$path}", ['Authorization' => "Bearer {$token}"]);
    }

    // --- Registry and review (§8–9, §16, §71) ---

    public function test_an_app_is_published_only_after_review_by_someone_else_and_its_secret_is_shown_once(): void
    {
        $created = $this->as($this->writer)->postJson('/api/v1/super-admin/marketplace/apps', ['slug' => 'ledger-sync', ...$this->listing()])->assertCreated();
        $id = $created->json('data.id');
        $secret = (string) $created->json('data.client_secret');
        $this->assertStringStartsWith('utas_', $secret);
        $this->assertSame(hash('sha256', $secret), DB::table('marketplace_apps')->value('client_secret_hash'));
        $this->as($this->writer)->getJson("/api/v1/super-admin/marketplace/apps/{$id}")->assertOk()->assertJsonMissingPath('data.client_secret');

        $this->as($this->writer)->putJson("/api/v1/super-admin/marketplace/apps/{$id}/version", $this->version('1.0.0', ['products:read'], ['config_schema' => [['key' => 'Bad Key', 'label' => '', 'type' => 'script']]]))
            ->assertUnprocessable()->assertJsonPath('code', 'settings_form_invalid');
        $this->as($this->writer)->putJson("/api/v1/super-admin/marketplace/apps/{$id}/version", $this->version('1.0', ['products:read']))->assertUnprocessable()->assertJsonPath('code', 'version_invalid');
        $this->as($this->writer)->putJson("/api/v1/super-admin/marketplace/apps/{$id}/version", $this->version())->assertOk();
        $this->as($this->writer)->postJson("/api/v1/super-admin/marketplace/apps/{$id}/submit")->assertOk();
        $checks = array_fill_keys(array_keys(MarketplaceCatalog::REVIEW_CHECKS), true);
        $this->as($this->writer)->postJson("/api/v1/super-admin/marketplace/apps/{$id}/publish", ['checks' => $checks])->assertUnprocessable()->assertJsonPath('code', 'self_review');
        $this->as($this->reviewer)->postJson("/api/v1/super-admin/marketplace/apps/{$id}/publish", ['checks' => [...$checks, 'privacy' => false]])->assertUnprocessable()->assertJsonPath('code', 'review_incomplete');
        $this->as($this->reviewer)->postJson("/api/v1/super-admin/marketplace/apps/{$id}/publish", ['checks' => $checks])->assertOk();

        $this->assertSame('published', MarketplaceApp::query()->value('status'));
        $auditor = User::factory()->create(['platform_role' => 'read_only_auditor']);
        $this->as($auditor)->getJson('/api/v1/super-admin/marketplace/apps')->assertOk()->assertJsonPath('data.0.status', 'published');
        $this->as($auditor)->postJson("/api/v1/super-admin/marketplace/apps/{$id}/deprecate")->assertForbidden();
        $this->assertTrue(AuditLog::query()->where('action', 'marketplace.version.published')->exists());
    }

    // --- Catalog and installation (§10–12, §19–21) ---

    public function test_stores_see_published_apps_and_only_the_owner_installs_with_the_package_it_needs(): void
    {
        [$app] = $this->publishedApp();
        [$basic, $basicOwner] = $this->store(['orders.basic']);
        [$store, $owner] = $this->store();
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $this->systemRole($store, 'manager')->id, 'status' => 'active']);

        $this->as($owner)->getJson('/api/v1/apps')->assertOk()->assertJsonPath('data.0.slug', 'ledger-sync')->assertJsonPath('data.0.high_risk', true);
        $detail = $this->as($owner)->getJson('/api/v1/apps/ledger-sync')->assertOk()->json('data');
        $this->assertSame(['products:read', 'orders:read'], array_column($detail['permissions'], 'scope'));
        $this->assertSame('high', $detail['permissions'][1]['risk']);
        $this->assertSame([], $detail['problems']);

        $this->as($basicOwner)->getJson('/api/v1/apps/ledger-sync')->assertOk()->assertJsonPath('data.problems.0.code', 'package');
        $this->as($basicOwner)->postJson('/api/v1/apps/ledger-sync/install', ['accept_permissions' => true])->assertUnprocessable()->assertJsonPath('code', 'package');
        $this->as($staff)->postJson('/api/v1/apps/ledger-sync/install', ['accept_permissions' => true])->assertForbidden();
        $this->as($owner)->postJson('/api/v1/apps/ledger-sync/install', [])->assertUnprocessable()->assertJsonValidationErrors('accept_permissions');

        $this->install($store, $owner);
        $this->assertSame('awaiting_connection', AppInstallation::query()->withoutTenantScope()->where('store_id', $store->id)->value('status'));
        $this->as($owner)->postJson('/api/v1/apps/ledger-sync/install', ['accept_permissions' => true])->assertUnprocessable()->assertJsonPath('code', 'already_installed');
        $this->assertSame(0, AppInstallation::query()->withoutTenantScope()->where('store_id', $basic->id)->count());
    }

    // --- Connection (§34–36) ---

    public function test_the_app_exchanges_the_code_once_for_a_key_of_exactly_the_granted_scopes_and_its_webhook(): void
    {
        [$app, $secret] = $this->publishedApp();
        [$store, $owner] = $this->store();
        [$code] = $this->install($store, $owner);

        $this->exchange($app, 'utas_wrong', $code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->exchange($app, $secret, $code, 'https://evil.example.com/steal')->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $token = $this->exchange($app, $secret, $code)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('scope', 'products:read orders:read')->assertJsonPath('store.id', $store->public_id)->json();
        $this->assertNotEmpty($token['webhook_signing_secret']);
        $this->exchange($app, $secret, $code)->assertStatus(400); // a code works once

        $this->api($token['access_token'], '/products')->assertOk();
        $this->api($token['access_token'], '/customers')->assertForbidden()->assertJsonPath('code', 'insufficient_scope');
        $this->api($token['access_token'], '/installation')->assertOk()->assertJsonPath('data.app.slug', 'ledger-sync')->assertJsonPath('data.scopes', ['products:read', 'orders:read']);

        $installation = AppInstallation::query()->withoutTenantScope()->where('store_id', $store->id)->firstOrFail();
        $this->assertSame('active', $installation->status);
        $subscription = DB::table('webhook_subscriptions')->where('developer_application_id', $installation->developer_application_id)->first();
        $this->assertSame(self::WEBHOOK, $subscription->url);
        $this->assertSame(['order.created'], json_decode($subscription->subscribed_events, true));
        $this->assertSame(0, DB::table('api_keys')->where('key_hash', hash('sha256', explode('.', $token['access_token'])[1]))->where('store_id', '!=', $store->id)->count());

        DB::table('app_authorization_codes')->update(['used_at' => null, 'expires_at' => now()->subMinute()]);
        $this->exchange($app, $secret, $code)->assertStatus(400); // an expired code
    }

    public function test_settings_follow_the_apps_form_and_secrets_are_never_sent_back(): void
    {
        [$app, $secret] = $this->publishedApp();
        [$store, $owner] = $this->store();
        [$code] = $this->install($store, $owner);
        $token = $this->exchange($app, $secret, $code)->json('access_token');

        $this->as($owner)->putJson('/api/v1/apps/ledger-sync/settings', ['settings' => ['mode' => 'weekly']])->assertUnprocessable()->assertJsonValidationErrors(['settings.book', 'settings.mode']);
        $this->as($owner)->putJson('/api/v1/apps/ledger-sync/settings', ['settings' => ['book' => 'Main book', 'api_password' => 'hunter2-secret', 'mode' => 'daily']])->assertOk();
        $this->as($owner)->getJson('/api/v1/apps/ledger-sync')->assertOk()
            ->assertJsonPath('data.installation.settings.book', 'Main book')->assertJsonPath('data.installation.settings.api_password', ['saved' => true]);
        $this->assertStringNotContainsString('hunter2', (string) DB::table('app_installations')->value('config'));
        $this->as($owner)->putJson('/api/v1/apps/ledger-sync/settings', ['settings' => ['book' => 'Second book', 'api_password' => '', 'mode' => 'daily']])->assertOk();

        $this->api($token, '/installation')->assertJsonPath('data.settings.api_password', 'hunter2-secret')->assertJsonPath('data.settings.book', 'Second book');
    }

    // --- Kill switch and lifecycle (§23, §58–59, §76, §86) ---

    public function test_the_kill_switch_stops_the_app_in_every_store_and_resuming_brings_back_only_what_ran(): void
    {
        [$app, $secret] = $this->publishedApp();
        [$storeA, $ownerA] = $this->store();
        [$storeB, $ownerB] = $this->store();
        [$codeA] = $this->install($storeA, $ownerA);
        $tokenA = $this->exchange($app, $secret, $codeA)->json('access_token');
        [$codeB] = $this->install($storeB, $ownerB);
        $tokenB = $this->exchange($app, $secret, $codeB)->json('access_token');
        $this->as($ownerB)->postJson('/api/v1/apps/ledger-sync/disable')->assertOk();
        $this->api($tokenB, '/products')->assertUnauthorized();

        $this->as($this->reviewer)->postJson("/api/v1/super-admin/marketplace/apps/{$app->public_id}/suspend", ['reason' => 'Leaked credentials reported'])->assertOk();
        $this->api($tokenA, '/products')->assertUnauthorized();
        $this->as($ownerA)->getJson('/api/v1/apps/ledger-sync')->assertJsonPath('data.installation.status', 'suspended')->assertJsonPath('data.installation.suspended_reason', 'Leaked credentials reported');

        Queue::fake();
        app(TenantContext::class)->resolveToStore($storeA->id);
        app(WebhookEventRouter::class)->route('order.created', $storeA->id, ['order_id' => 1, 'order_number' => 'O-1', 'grand_total_minor' => 100], 'order:1:created');
        Queue::assertNothingPushed();

        $this->as($this->reviewer)->postJson("/api/v1/super-admin/marketplace/apps/{$app->public_id}/resume")->assertOk();
        $this->api($tokenA, '/products')->assertOk();
        $this->api($tokenB, '/products')->assertUnauthorized(); // it was paused by its store before: it stays paused
        $this->assertSame('disabled', AppInstallation::query()->withoutTenantScope()->where('store_id', $storeB->id)->value('status'));
    }

    public function test_a_suspended_developer_application_no_longer_has_api_access(): void
    {
        [$store] = $this->store();
        app(TenantContext::class)->resolveToStore($store->id);
        $application = app(ApplicationService::class)->create($store, 'Private tool', null);
        $token = app(ApiKeyService::class)->issue($application, [ApiScope::ProductsRead->value])['plaintext'];
        $this->api($token, '/products')->assertOk();

        app(ApplicationService::class)->suspend(DeveloperApplication::query()->withoutTenantScope()->findOrFail($application->id));
        $this->api($token, '/products')->assertUnauthorized();
    }

    public function test_an_update_with_new_permissions_needs_consent_and_uninstalling_ends_access_at_once(): void
    {
        [$app, $secret] = $this->publishedApp(['products:read']);
        [$store, $owner] = $this->store();
        [$code] = $this->install($store, $owner);
        $token = $this->exchange($app, $secret, $code)->json('access_token');
        $this->api($token, '/customers')->assertForbidden();

        $this->as($this->writer)->putJson("/api/v1/super-admin/marketplace/apps/{$app->public_id}/version", $this->version('1.0.0', ['products:read', 'customers:read']))->assertUnprocessable()->assertJsonPath('code', 'version_not_newer');
        $this->as($this->writer)->putJson("/api/v1/super-admin/marketplace/apps/{$app->public_id}/version", $this->version('1.1.0', ['products:read', 'customers:read']))->assertOk();
        $this->as($this->writer)->postJson("/api/v1/super-admin/marketplace/apps/{$app->public_id}/submit")->assertOk();
        $this->as($this->reviewer)->postJson("/api/v1/super-admin/marketplace/apps/{$app->public_id}/publish", ['checks' => array_fill_keys(array_keys(MarketplaceCatalog::REVIEW_CHECKS), true)])->assertOk();

        $this->as($owner)->getJson('/api/v1/apps/ledger-sync')->assertJsonPath('data.installation.update.version', '1.1.0')->assertJsonPath('data.installation.update.new_permissions.0.scope', 'customers:read');
        $this->as($owner)->postJson('/api/v1/apps/ledger-sync/update')->assertUnprocessable()->assertJsonPath('code', 'consent_required');
        $this->as($owner)->postJson('/api/v1/apps/ledger-sync/update', ['accept_permissions' => true])->assertOk();
        $this->api($token, '/customers')->assertOk();

        $this->as($owner)->deleteJson('/api/v1/apps/ledger-sync')->assertOk();
        $this->api($token, '/products')->assertUnauthorized();
        $installation = AppInstallation::query()->withoutTenantScope()->where('store_id', $store->id)->firstOrFail();
        $this->assertSame(['uninstalled', []], [$installation->status, $installation->config]);
        $this->assertSame(0, DB::table('webhook_subscriptions')->where('developer_application_id', $installation->developer_application_id)->where('status', 'active')->count());
        $this->install($store, $owner); // it can be installed again
    }

    public function test_every_permission_an_app_may_ask_for_is_a_developer_api_scope(): void
    {
        $scopes = array_map(fn (ApiScope $s) => $s->value, ApiScope::cases());
        foreach (MarketplaceCatalog::scopes() as $scope) {
            $this->assertContains($scope, $scopes);
        }
    }

    public function test_one_stores_installation_is_invisible_to_another(): void
    {
        [$app, $secret] = $this->publishedApp();
        [$storeA, $ownerA] = $this->store();
        [, $ownerB] = $this->store();
        [$code] = $this->install($storeA, $ownerA);
        $this->exchange($app, $secret, $code)->assertOk();

        $this->as($ownerB)->getJson('/api/v1/apps/ledger-sync')->assertOk()->assertJsonPath('data.installation', null);
        $this->as($ownerB)->deleteJson('/api/v1/apps/ledger-sync')->assertNotFound();
        $this->as($ownerB)->getJson('/api/v1/apps')->assertJsonPath('data.0.installed', null);
    }
}
