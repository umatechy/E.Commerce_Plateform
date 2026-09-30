<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Domain\Notifications\Models\NotificationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Phase B25 — "forgot password": no enumeration, single-use expiring tokens. */
final class CustomerPasswordResetTest extends TestCase
{
    use InteractsWithCustomerAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /** The token from the reset email's link. */
    private function mailedToken(): string
    {
        $body = NotificationMessage::query()->withoutTenantScope()->where('source_event_type', 'customer.password_reset_requested')->latest('id')->firstOrFail()->body;
        preg_match('/token=([A-Za-z0-9]{64})/', $body, $m);

        return $m[1];
    }

    public function test_a_reset_link_is_mailed_and_the_answer_never_reveals_whether_the_account_exists(): void
    {
        $store = $this->openStore();
        $this->registered($store, ['email' => 'amna@example.com']);
        $this->registered($store, ['email' => 'guest@example.com', 'password' => null]);
        $h = ['X-Store-Slug' => $store->slug];

        foreach (['amna@example.com', 'nobody@example.com', 'guest@example.com'] as $email) {
            $this->postJson('/api/v1/storefront/password/forgot', ['email' => $email], $h)
                ->assertStatus(202)->assertJsonPath('message', 'If an account exists for this email, a reset link is on its way.');
        }

        $mail = NotificationMessage::query()->withoutTenantScope()->where('source_event_type', 'customer.password_reset_requested')->sole();
        $this->assertSame('amna@example.com', $mail->destination);
        $this->assertStringContainsString('/account/reset-password?token=', $mail->body);
        $this->assertSame(1, DB::table('customer_password_resets')->count());
        $this->assertNotSame($this->mailedToken(), DB::table('customer_password_resets')->value('token_hash')); // only a hash is stored

        // A second request within a minute sends nothing more.
        $this->postJson('/api/v1/storefront/password/forgot', ['email' => 'amna@example.com'], $h)->assertStatus(202);
        $this->assertSame(1, NotificationMessage::query()->withoutTenantScope()->where('source_event_type', 'customer.password_reset_requested')->count());
    }

    public function test_the_token_resets_the_password_once_and_signs_out_everywhere(): void
    {
        $store = $this->openStore();
        $customer = $this->registered($store, ['email' => 'amna@example.com']);
        $customer->createToken('old-session');
        $h = ['X-Store-Slug' => $store->slug];
        $this->postJson('/api/v1/storefront/password/forgot', ['email' => 'amna@example.com'], $h);
        $token = $this->mailedToken();
        $payload = ['email' => 'AMNA@example.com', 'token' => $token, 'password' => 'my-new-password', 'password_confirmation' => 'my-new-password'];

        $this->postJson('/api/v1/storefront/password/reset', $payload, $h)->assertOk();

        $customer->refresh();
        $this->assertTrue(Hash::check('my-new-password', $customer->password));
        $this->assertNotNull($customer->email_verified_at);
        $this->assertSame(0, $customer->tokens()->count());
        $this->postJson('/api/v1/storefront/password/reset', $payload, $h)->assertStatus(422)->assertJsonPath('code', 'invalid_token');
        $this->postJson('/api/v1/storefront/session', ['email' => 'amna@example.com', 'password' => 'my-new-password'], $h)->assertOk();
    }

    public function test_expired_foreign_or_mismatched_tokens_are_refused(): void
    {
        $store = $this->openStore();
        $this->registered($store, ['email' => 'amna@example.com']);
        $other = $this->openStore();
        $h = ['X-Store-Slug' => $store->slug];
        $this->postJson('/api/v1/storefront/password/forgot', ['email' => 'amna@example.com'], $h);
        $token = $this->mailedToken();
        $payload = ['email' => 'amna@example.com', 'token' => $token, 'password' => 'my-new-password', 'password_confirmation' => 'my-new-password'];

        $this->postJson('/api/v1/storefront/password/reset', [...$payload, 'email' => 'someone@example.com'], $h)->assertStatus(422);
        $this->postJson('/api/v1/storefront/password/reset', $payload, ['X-Store-Slug' => $other->slug])->assertStatus(422);

        $this->travel(61)->minutes();
        $this->postJson('/api/v1/storefront/password/reset', $payload, $h)->assertStatus(422)->assertJsonPath('code', 'invalid_token');
    }
}
