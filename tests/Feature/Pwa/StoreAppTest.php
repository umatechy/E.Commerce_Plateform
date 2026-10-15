<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Pwa\Models\PushSubscription;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use App\Domain\Tenancy\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase B55 (gap G16; Module 25 §5–28, §51–54, §63–69, §83, §87–89): the
 * store's installable app — manifest, service worker, offline page,
 * settings, icon, tenant isolation, private pages, push foundation and
 * counters.
 */
final class StoreAppTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Storage::fake((string) config('storefront.images.disk'));
        $this->store = $this->openStore();
    }

    private function openStore(array $features = ['orders.basic', 'pwa.enabled']): Store
    {
        $store = Store::factory()->create(['status' => 'active']);
        $this->entitle($store, $features);

        return $store;
    }

    private function member(Store $store, string $role): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, $role)->id, 'status' => 'active']);

        return $user;
    }

    private function setting(Store $store, string $key, mixed $value): void
    {
        app(TenantContext::class)->resolveToStore($store->id);
        app(ConfigService::class)->set($key, $value, SettingScope::Store, null);
        Cache::flush();
    }

    private function base(Store $store): string
    {
        return "/shop/{$store->slug}";
    }

    private function icon(Store $store, User $owner, int $width = 512, int $height = 512): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($owner)->post('/api/v1/pwa/icon', ['file' => UploadedFile::fake()->image('icon.png', $width, $height)], ['Accept' => 'application/json']);
    }

    // --- Manifest (§5–9, §28) ---

    public function test_the_manifest_belongs_to_the_store_and_stays_inside_its_address(): void
    {
        $owner = $this->member($this->store, 'owner');
        $this->icon($this->store, $owner)->assertOk();
        $this->setting($this->store, 'pwa.name', 'Ittar <b>House</b>');
        $base = $this->base($this->store);

        $this->app['auth']->forgetGuards();
        $manifest = $this->get("{$base}/manifest.webmanifest")->assertOk()->assertHeader('Content-Type', 'application/manifest+json')->json();

        $this->assertSame('Ittar House', $manifest['name'], 'markup is removed');
        $this->assertStringNotContainsString('<', $manifest['name']);
        $this->assertSame("{$base}/?source=app", $manifest['start_url']);
        $this->assertSame("{$base}/", $manifest['scope']);
        $this->assertSame(['192x192', '512x512'], array_column($manifest['icons'], 'sizes'));
        foreach ([...array_column($manifest['icons'], 'src'), ...array_column($manifest['shortcuts'], 'url')] as $url) {
            $this->assertTrue(str_starts_with($url, "/storage/stores/{$this->store->public_id}/") || str_starts_with($url, "{$base}/"), "{$url} leaves the store");
        }
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $manifest['theme_color']);
    }

    public function test_no_manifest_without_the_package_feature_or_with_the_app_off_or_the_shop_closed(): void
    {
        $basic = $this->openStore(['orders.basic']);
        $this->get($this->base($basic).'/manifest.webmanifest')->assertNotFound();

        $this->setting($this->store, 'pwa.enabled', false);
        $this->get($this->base($this->store).'/manifest.webmanifest')->assertNotFound();
    }

    // --- Service worker (§10–26, §65, §83) ---

    public function test_the_worker_is_scoped_to_its_store_never_caches_live_data_and_is_never_cached_itself(): void
    {
        $other = $this->openStore();
        $script = $this->get($this->base($this->store).'/sw.js')->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')->getContent();
        $this->assertStringContainsString('no-store', (string) $this->get($this->base($this->store).'/sw.js')->headers->get('Cache-Control'));

        preg_match('/const CONFIG = (\{.*?\});/s', (string) $script, $m);
        $config = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($this->store->public_id, $config['store']);
        $this->assertSame($this->base($this->store), $config['base']);
        $this->assertSame("/storage/stores/{$this->store->public_id}/", $config['media']);
        $this->assertSame(['/account', '/checkout', '/cart', '/support', '/returns'], $config['private']);
        $this->assertFalse($config['retired']);
        $this->assertStringContainsString("url.pathname.startsWith('/api/')", (string) $script);
        $this->assertStringContainsString("request.headers.has('X-Inertia')", (string) $script);
        $this->assertStringContainsString("'ut-' + CONFIG.store", (string) $script, 'cache names carry the store');

        $otherScript = (string) $this->get($this->base($other).'/sw.js')->getContent();
        $this->assertStringContainsString($other->public_id, $otherScript);
        $this->assertStringNotContainsString($this->store->public_id, $otherScript);
    }

    public function test_a_closed_shop_or_a_switched_off_app_gets_a_worker_that_retires(): void
    {
        $retired = fn (Store $store): bool => json_decode((string) preg_replace('/^.*const CONFIG = (\{.*?\});.*$/s', '$1', (string) $this->get($this->base($store).'/sw.js')->assertOk()->getContent()), true)['retired'];

        $this->store->update(['status' => StoreStatus::Cancelled]);
        $this->assertTrue($retired($this->store), 'a closed shop still answers sw.js, retiring');
        $this->get($this->base($this->store).'/')->assertStatus(503);

        $open = $this->openStore();
        $this->setting($open, 'pwa.enabled', false);
        $this->assertTrue($retired($open));
    }

    public function test_the_version_changes_when_the_app_settings_change(): void
    {
        $owner = $this->member($this->store, 'owner');
        $version = fn (): string => json_decode((string) preg_replace('/^.*const CONFIG = (\{.*?\});.*$/s', '$1', (string) $this->get($this->base($this->store).'/sw.js')->getContent()), true)['version'];
        $before = $version();

        $this->actingAs($owner)->putJson('/api/v1/pwa/settings', ['enabled' => true, 'name' => 'Ittar', 'short_name' => null, 'theme_color' => '#112233', 'background_color' => null, 'display' => 'standalone', 'install_prompt' => true, 'mobile_nav' => 'always', 'push' => false])->assertOk();
        $this->app['auth']->forgetGuards();
        Cache::flush();
        $this->assertNotSame($before, $version());
    }

    public function test_the_offline_page_is_branded_and_escaped(): void
    {
        $this->setting($this->store, 'pwa.name', 'Rose & "Oud"');
        $html = (string) $this->get($this->base($this->store).'/offline')->assertOk()->getContent();

        $this->assertStringContainsString('You are offline', $html);
        $this->assertStringContainsString('Rose &amp; &quot;Oud&quot;', $html);
        $this->assertStringContainsString('href="'.$this->base($this->store).'/"', $html);
    }

    // --- Storefront pages (§5, §14, §66) ---

    public function test_storefront_pages_link_the_manifest_and_pages_seen_by_signed_in_staff_are_never_kept_offline(): void
    {
        $base = $this->base($this->store);
        $guest = $this->withoutVite()->get("{$base}/")->assertOk();
        $this->assertStringContainsString('<link rel="manifest" href="'.$base.'/manifest.webmanifest">', (string) $guest->getContent());
        $guest->assertHeaderMissing('X-Ut-Private');
        $guest->assertInertia(fn (AssertableInertia $p) => $p->where('storefront.pwa.enabled', true)->where('storefront.pwa.worker_url', "{$base}/sw.js")->where('storefront.pwa.push_key', null));

        $staff = $this->member($this->store, 'staff');
        $this->actingAs($staff)->withoutVite()->get("{$base}/")->assertOk()->assertHeader('X-Ut-Private', '1');
    }

    // --- Settings (§7, §76) ---

    public function test_only_people_who_may_change_the_theme_change_the_app_and_only_on_its_own_page(): void
    {
        $owner = $this->member($this->store, 'owner');
        $staff = $this->member($this->store, 'staff');
        $valid = ['enabled' => true, 'name' => 'Ittar', 'short_name' => 'Ittar', 'theme_color' => '#112233', 'background_color' => '#FFFFFF', 'display' => 'standalone', 'install_prompt' => true, 'mobile_nav' => 'installed', 'push' => false];

        $this->actingAs($staff)->getJson('/api/v1/pwa/settings')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner)->getJson('/api/v1/pwa/settings')->assertOk()->assertJsonPath('data.included', true)->assertJsonPath('data.push_available', false);
        $this->actingAs($owner)->putJson('/api/v1/pwa/settings', [...$valid, 'theme_color' => 'red'])->assertUnprocessable()->assertJsonValidationErrors('theme_color');
        $this->actingAs($owner)->putJson('/api/v1/pwa/settings', [...$valid, 'name' => '<script>'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($owner)->putJson('/api/v1/pwa/settings', [...$valid, 'push' => true])->assertUnprocessable()->assertJsonPath('code', 'push_not_configured');
        $this->actingAs($owner)->putJson('/api/v1/pwa/settings', $valid)->assertOk();
        $this->actingAs($owner)->putJson('/api/v1/store/settings/pwa.name', ['value' => 'Sneaky'])->assertForbidden()->assertJsonPath('code', 'managed_on_app_page');

        app(TenantContext::class)->resolveToStore($this->store->id);
        $this->assertSame('#112233', app(ConfigService::class)->get('pwa.theme_color'));
    }

    public function test_the_app_icon_must_be_square_and_is_made_in_the_sizes_phones_need(): void
    {
        $owner = $this->member($this->store, 'owner');
        $this->icon($this->store, $owner, 600, 400)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->icon($this->store, $owner, 256, 256)->assertUnprocessable();
        $path = $this->icon($this->store, $owner)->assertOk()->json('data.icon');

        $disk = Storage::disk((string) config('storefront.images.disk'));
        foreach ([192, 512] as $size) {
            $file = str_replace('-512.png', "-{$size}.png", substr($path, strlen('/storage/')));
            $this->assertTrue($disk->exists($file));
            $this->assertSame([$size, $size], array_slice((array) getimagesizefromstring((string) $disk->get($file)), 0, 2));
        }
        $checks = collect($this->actingAs($owner)->getJson('/api/v1/pwa/settings')->json('data.checks'))->keyBy('key');
        $this->assertTrue($checks['icon']['ok']);
    }

    // --- Push foundation (§51–54) ---

    public function test_push_subscriptions_need_the_platform_keys_a_signed_in_shopper_and_stay_with_their_store(): void
    {
        $customer = Customer::factory()->for($this->store)->create(['password' => Hash::make('x')]);
        $token = $customer->createToken('t')->plainTextToken;
        $body = ['endpoint' => 'https://push.example.com/send/abc123', 'keys' => ['p256dh' => 'BPublicKey_abc-123', 'auth' => 'authSecret_1']];
        $headers = ['X-Store-Slug' => $this->store->slug];

        $this->postJson('/api/v1/storefront/app/push', $body, ['Authorization' => "Bearer {$token}", ...$headers])->assertNotFound(); // no keys on the platform
        config(['pwa.vapid.public_key' => 'BTestPublicKey', 'pwa.vapid.private_key' => 'test-private']);
        $this->setting($this->store, 'pwa.push', true);

        $this->app['auth']->forgetGuards(); // a new visitor: no token from the request before
        $this->postJson('/api/v1/storefront/app/push', $body, $headers)->assertUnauthorized();
        $this->postJson('/api/v1/storefront/app/push', [...$body, 'endpoint' => 'http://push.example.com/x'], ['Authorization' => "Bearer {$token}", ...$headers])->assertUnprocessable();
        $this->postJson('/api/v1/storefront/app/push', $body, ['Authorization' => "Bearer {$token}", ...$headers, 'User-Agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0'])->assertCreated();

        $row = DB::table('pwa_push_subscriptions')->sole();
        $this->assertSame($this->store->id, $row->store_id);
        $this->assertSame($customer->id, $row->customer_id);
        $this->assertNotSame($body['endpoint'], $row->endpoint, 'encrypted at rest');
        $this->assertSame(hash('sha256', $body['endpoint']), $row->endpoint_hash);
        $this->assertSame('Chrome on Android', $row->browser);

        $other = $this->openStore();
        app(TenantContext::class)->resolveToStore($other->id);
        $this->assertSame(0, PushSubscription::query()->count(), 'another store sees none of them');

        $this->postJson('/api/v1/storefront/app/push', $body, ['Authorization' => "Bearer {$token}", ...$headers])->assertCreated(); // the same device again: one row
        $this->assertSame(1, DB::table('pwa_push_subscriptions')->count());
        $this->deleteJson('/api/v1/storefront/app/push', ['endpoint' => $body['endpoint']], ['Authorization' => "Bearer {$token}", ...$headers])->assertOk();
        $this->assertSame('revoked', DB::table('pwa_push_subscriptions')->value('status'));
        $this->assertSame([2, 1], [(int) DB::table('pwa_daily_stats')->where('event', 'push_on')->value('count'), (int) DB::table('pwa_daily_stats')->where('event', 'push_off')->value('count')]);
    }

    public function test_app_counters_take_only_known_events_and_no_device_identity(): void
    {
        $headers = ['X-Store-Slug' => $this->store->slug];
        $this->postJson('/api/v1/storefront/app/events', ['event' => 'installed'], $headers)->assertNoContent();
        $this->postJson('/api/v1/storefront/app/events', ['event' => 'installed'], $headers)->assertNoContent();
        $this->postJson('/api/v1/storefront/app/events', ['event' => 'tracked_you'], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/storefront/app/events', ['event' => 'push_on'], $headers)->assertUnprocessable();

        $this->assertSame(2, (int) DB::table('pwa_daily_stats')->where(['store_id' => $this->store->id, 'event' => 'installed'])->value('count'));
        $this->assertSame(['id', 'store_id', 'day', 'event', 'count'], array_keys((array) DB::table('pwa_daily_stats')->first()));

        $this->setting($this->store, 'pwa.enabled', false);
        $this->postJson('/api/v1/storefront/app/events', ['event' => 'launched'], $headers)->assertNotFound();
    }

    public function test_the_vapid_command_prints_a_p256_key_pair_and_writes_nothing(): void
    {
        $this->assertSame(0, Artisan::call('pwa:vapid-keys'));
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/PWA_VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{87}\b/', $output);
        $this->assertMatchesRegularExpression('/PWA_VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{43}\b/', $output);
    }
}
