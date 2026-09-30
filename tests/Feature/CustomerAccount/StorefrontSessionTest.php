<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\Models\Product;
use App\Domain\Compliance\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Phase B25 — the web storefront's session: the token lives only in an
 * HttpOnly cookie, is honoured only with the X-Storefront-Request header
 * (CSRF), and expires.
 */
final class StorefrontSessionTest extends TestCase
{
    use InteractsWithCustomerAccounts, RefreshDatabase;

    public function test_signing_in_sets_an_http_only_cookie_and_never_returns_the_token(): void
    {
        $store = $this->openStore();
        $customer = $this->registered($store, ['email' => 'amna@example.com']);

        $response = $this->postJson('/api/v1/storefront/session', ['email' => 'amna@example.com', 'password' => 'correct-horse-99'], ['X-Store-Slug' => $store->slug]);

        $response->assertOk()->assertJsonPath('data.email', 'amna@example.com')->assertJsonMissingPath('token');
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === "sf_session_{$store->slug}");
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertStringNotContainsString($cookie->getValue(), $response->getContent());

        $token = PersonalAccessToken::findToken($cookie->getValue());
        $this->assertSame($customer->id, $token->tokenable_id);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertTrue(AuditLog::query()->where('action', 'auth.login.succeeded')->where('store_id', $store->id)->exists());
    }

    public function test_the_cookie_only_authenticates_requests_carrying_the_storefront_header(): void
    {
        $store = $this->openStore();
        $customer = $this->registered($store);
        $token = $customer->createToken('storefront-session')->plainTextToken;
        $cookie = "sf_session_{$store->slug}";

        $this->withCredentials()->withUnencryptedCookie($cookie, $token)
            ->getJson('/api/v1/customer/profile', ['X-Store-Slug' => $store->slug, 'X-Storefront-Request' => '1'])
            ->assertOk()->assertJsonPath('data.id', $customer->public_id);

        // A cross-site request carries the cookie but cannot add the header.
        $this->withCredentials()->withUnencryptedCookie($cookie, $token)
            ->getJson('/api/v1/customer/profile', ['X-Store-Slug' => $store->slug])
            ->assertUnauthorized();

        // Another store's cookie name never matches this store. (Test
        // cookies persist between requests, so start from none.)
        $this->unencryptedCookies = [];
        $this->withCredentials()->withUnencryptedCookie('sf_session_other-store', $token)
            ->getJson('/api/v1/customer/profile', ['X-Store-Slug' => $store->slug, 'X-Storefront-Request' => '1'])
            ->assertUnauthorized();
    }

    public function test_signing_out_revokes_the_token_and_clears_the_cookie(): void
    {
        $store = $this->openStore();
        $customer = $this->registered($store);
        $token = $customer->createToken('storefront-session')->plainTextToken;
        $headers = ['X-Store-Slug' => $store->slug, 'X-Storefront-Request' => '1'];

        $response = $this->withCredentials()->withUnencryptedCookie("sf_session_{$store->slug}", $token)->deleteJson('/api/v1/storefront/session', [], $headers);

        $response->assertNoContent();
        $cleared = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === "sf_session_{$store->slug}");
        $this->assertTrue($cleared->isCleared());
        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_registering_signs_the_new_customer_in(): void
    {
        $store = $this->openStore();

        $response = $this->postJson('/api/v1/storefront/session/register', [
            'name' => 'Amna Rauf', 'email' => 'amna@example.com', 'password' => 'long-enough-pw', 'password_confirmation' => 'long-enough-pw',
        ], ['X-Store-Slug' => $store->slug]);

        $response->assertCreated()->assertJsonPath('data.name', 'Amna Rauf')->assertJsonMissingPath('token');
        $this->assertNotNull(collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === "sf_session_{$store->slug}"));
        $this->assertTrue(AuditLog::query()->where('action', 'customer.registered')->where('store_id', $store->id)->exists());

        $this->postJson('/api/v1/storefront/session/register', [
            'name' => 'Again', 'email' => 'amna@example.com', 'password' => 'long-enough-pw', 'password_confirmation' => 'long-enough-pw',
        ], ['X-Store-Slug' => $store->slug])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_wrong_password_sets_no_cookie(): void
    {
        $store = $this->openStore();
        $this->registered($store, ['email' => 'amna@example.com']);

        $response = $this->postJson('/api/v1/storefront/session', ['email' => 'amna@example.com', 'password' => 'wrong-password'], ['X-Store-Slug' => $store->slug]);

        $response->assertStatus(422);
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_the_guest_cart_joins_the_account_on_sign_in(): void
    {
        $store = $this->openStore();
        $this->registered($store, ['email' => 'amna@example.com']);
        $product = Product::factory()->create(['store_id' => $store->id, 'status' => 'active', 'visibility' => 'public', 'price_minor' => 1500]);
        $guest = $this->postJson('/api/v1/cart/items', ['product' => $product->public_id, 'quantity' => 2], ['X-Store-Slug' => $store->slug])->assertCreated();

        $this->postJson('/api/v1/storefront/session', ['email' => 'amna@example.com', 'password' => 'correct-horse-99'], [
            'X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $guest->json('data.guest_token'),
        ])->assertOk();

        $this->assertSame(2, (int) Cart::query()->whereNotNull('customer_id')->sole()->items()->sum('quantity'));
    }
}
