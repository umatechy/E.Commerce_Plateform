<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Deployment: behind the production proxies the app sees the visitor's IP
 * and HTTPS, but never lets a forwarded header choose the host (the host
 * picks the store).
 */
final class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__proxy-probe', fn (\Illuminate\Http\Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
        ]);
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    private function probe(string $from): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $from])->withHeaders([
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'another-store.example',
        ])->getJson('/__proxy-probe');
    }

    public function test_a_configured_proxy_gives_the_visitor_ip_and_https_but_not_the_host(): void
    {
        TrustedProxies::apply('172.30.240.0/24, 10.9.9.9');

        $response = $this->probe('172.30.240.1')->assertOk()
            ->assertJsonPath('ip', '203.0.113.7')
            ->assertJsonPath('secure', true);
        // The request's own Host stays; the forwarded one is ignored.
        $this->assertNotSame('another-store.example', $response->json('host'));
    }

    public function test_a_request_from_elsewhere_is_taken_as_it_is(): void
    {
        TrustedProxies::apply('172.30.240.0/24');

        $this->probe('198.51.100.20')->assertOk()
            ->assertJsonPath('ip', '198.51.100.20')
            ->assertJsonPath('secure', false);
    }

    public function test_nothing_configured_trusts_no_proxy(): void
    {
        TrustedProxies::apply('  ');

        $this->probe('172.30.240.1')->assertOk()
            ->assertJsonPath('ip', '172.30.240.1')
            ->assertJsonPath('secure', false);
    }
}
