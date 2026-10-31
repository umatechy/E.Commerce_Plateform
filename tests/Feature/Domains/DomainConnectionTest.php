<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Services\DnsResolverContract;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase S2 (owner decision 2026-10-31): a store is public only on its own
 * domain. Ownership (TXT), address (A record = the shop's IPv4) and
 * certificate are checked in order; then the domain goes live and becomes
 * the store's address. The shop's proxy may only get certificates for
 * proven domains; platform subdomains are never served; the platform
 * address of an open store forwards to its domain.
 */
final class DomainConnectionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> */
    private array $txt = [];

    /** @var array<string, list<string>> */
    private array $a = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['domains.shop_ipv4' => '203.0.113.10']);
        $test = $this;
        $this->app->instance(DnsResolverContract::class, new class($test) implements DnsResolverContract {
            public function __construct(private DomainConnectionTest $test) {}

            public function lookupTxtRecords(string $hostname): array
            {
                return $this->test->answers('txt', $hostname);
            }

            public function lookupARecords(string $hostname): array
            {
                return $this->test->answers('a', $hostname);
            }
        });
    }

    /** @return list<string> */
    public function answers(string $type, string $hostname): array
    {
        return ($type === 'txt' ? $this->txt : $this->a)[$hostname] ?? [];
    }

    /** @return array{0: Store, 1: User} */
    private function store(string $status = 'active'): array
    {
        $store = Store::factory()->create(['status' => $status]);
        $this->entitle($store, ['domains.custom_domain', 'orders.basic']);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return [$store, $owner];
    }

    public function test_a_domain_goes_live_step_by_step_and_becomes_the_stores_address(): void
    {
        [$store, $owner] = $this->store();
        $id = $this->actingAs($owner)->postJson('/api/v1/domains', ['hostname' => 'RoseAttars.pk'])->assertCreated()->json('data.id');

        $list = $this->actingAs($owner)->getJson('/api/v1/domains')->assertOk()->json();
        $this->assertSame('203.0.113.10', $list['meta']['shop_ipv4']);
        $this->assertCount(1, $list['data'], 'platform subdomains are not listed');
        $records = collect($list['data'][0]['records']);
        $txt = $records->firstWhere('type', 'TXT');
        $this->assertSame('_umartechy-verify.roseattars.pk', $txt['name']);
        $this->assertSame([['A', 'roseattars.pk', '203.0.113.10'], ['A', 'www.roseattars.pk', '203.0.113.10']], $records->where('type', 'A')->map(fn ($r) => [$r['type'], $r['name'], $r['value']])->values()->all());

        // 1. No TXT record yet: it waits, nothing fails.
        $this->actingAs($owner)->postJson("/api/v1/domains/{$id}/check")->assertOk()->assertJsonPath('data.status', 'verification_required');
        // 2. Ownership proven; the address points elsewhere.
        $this->txt['_umartechy-verify.roseattars.pk'] = [$txt['value']];
        $this->a['roseattars.pk'] = ['198.51.100.7'];
        $this->actingAs($owner)->postJson("/api/v1/domains/{$id}/check")->assertOk()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.dns_status', 'elsewhere');
        // 3. Pointing at the shop, but no certificate yet.
        $this->a['roseattars.pk'] = ['203.0.113.10'];
        $certificate = false;
        Http::fake(function () use (&$certificate) {
            return $certificate ? Http::response('', 404) : throw new ConnectionException('TLS handshake failed');
        });
        $this->actingAs($owner)->postJson("/api/v1/domains/{$id}/check")->assertOk()->assertJsonPath('data.dns_status', 'pointing')->assertJsonPath('data.ssl_status', 'pending')->assertJsonPath('data.status', 'verified');
        // 4. Certificate works (any answer over valid TLS, even a 404): live, and the store's address.
        $certificate = true;
        $this->actingAs($owner)->postJson("/api/v1/domains/{$id}/check")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.ssl_status', 'active')->assertJsonPath('data.is_primary', true);
        app(\App\Domain\Tenancy\Support\TenantContext::class)->resolveToStore($store->id);
        $this->assertSame('https://roseattars.pk', app(\App\Domain\Seo\Services\SeoResolver::class)->forStoreHome($store)->canonicalUrl);
    }

    public function test_the_scheduled_check_moves_domains_along_by_itself(): void
    {
        [$store] = $this->store();
        $domain = Domain::query()->withoutTenantScope()->create(['store_id' => $store->id, 'hostname' => 'shop.example.pk', 'normalized_hostname' => 'shop.example.pk', 'domain_type' => 'custom_domain',
            'status' => 'verification_required', 'verification_token' => 'tok', 'verification_token_expires_at' => now()->addDay()]);
        $this->txt['_umartechy-verify.shop.example.pk'] = ['umartechy-verify=tok'];
        $this->a['shop.example.pk'] = ['203.0.113.10'];
        Http::fake(['https://shop.example.pk/*' => Http::response('ok', 200)]);

        Artisan::call('domains:check-connections');
        $this->assertSame(['active', 'active', true], [$domain->fresh()->status->value, $domain->fresh()->ssl_status->value, $domain->fresh()->is_primary]);
    }

    public function test_the_proxy_may_get_certificates_only_for_proven_customer_domains(): void
    {
        [$store] = $this->store();
        $make = fn (string $host, string $status, string $type = 'custom_domain') => Domain::query()->withoutTenantScope()->create(['store_id' => $store->id, 'hostname' => $host, 'normalized_hostname' => $host, 'domain_type' => $type, 'status' => $status]);
        $make('proven.pk', 'verified');
        $make('live.pk', 'active');
        $make('unproven.pk', 'verification_required');
        $make('gone.pk', 'removed');

        foreach (['proven.pk' => 200, 'www.proven.pk' => 200, 'live.pk' => 200, 'unproven.pk' => 404, 'gone.pk' => 404, 'nobody.pk' => 404, $store->slug.'.'.config('domains.platform_base_domain') => 404, '' => 404] as $host => $status) {
            $this->get('/api/v1/public/tls-allowed?domain='.$host)->assertStatus($status);
        }
    }

    public function test_an_open_store_is_public_on_its_own_domain_only(): void
    {
        [$store] = $this->store();
        $subdomain = $store->slug.'.'.config('domains.platform_base_domain');
        // Platform subdomains are records only.
        $this->get("http://{$subdomain}/")->assertNotFound();

        Domain::query()->withoutTenantScope()->create(['store_id' => $store->id, 'hostname' => 'roseattars.pk', 'normalized_hostname' => 'roseattars.pk', 'domain_type' => 'custom_domain',
            'status' => 'active', 'ssl_status' => 'active', 'is_primary' => true, 'verified_at' => now()]);
        Domain::query()->withoutTenantScope()->where('store_id', $store->id)->where('domain_type', 'platform_subdomain')->update(['is_primary' => false]);

        $this->get("/shop/{$store->slug}/products?sort=newest")->assertRedirect('https://roseattars.pk/products?sort=newest')->assertStatus(301);
        $this->get('http://www.roseattars.pk/products/blue')->assertRedirect('https://roseattars.pk/products/blue')->assertStatus(301);
        $this->withoutVite()->get('http://roseattars.pk/')->assertOk();
        // The shop's API keeps working by slug (checkout, cart).
        $this->getJson('/api/v1/storefront/products', ['X-Store-Slug' => $store->slug])->assertOk();

        // Before launch the store is not forwarded: only its team previews it.
        $store->update(['status' => StoreStatus::PendingSetup]);
        $this->get("/shop/{$store->slug}/products")->assertStatus(503);
    }
}
