<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Domain\Compliance\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Phase B25 — profile, email and password changes by the customer. */
final class CustomerProfileTest extends TestCase
{
    use InteractsWithCustomerAccounts, RefreshDatabase;

    public function test_profile_details_and_marketing_consent_are_updated_and_consent_audited(): void
    {
        $store = $this->openStore();
        $customer = $this->registered($store);
        $h = $this->as($customer);

        $this->patchJson('/api/v1/customer/profile', ['name' => 'Amna Rauf', 'phone' => '+92 300 7654321', 'marketing_email_opt_in' => true], $h)
            ->assertOk()->assertJsonPath('data.name', 'Amna Rauf')->assertJsonPath('data.marketing_email_opt_in', true);

        $this->assertTrue(AuditLog::query()->where('action', 'customer.marketing_consent_changed')->where('store_id', $store->id)->exists());
        $this->patchJson('/api/v1/customer/profile', ['phone' => 'call me'], $h)->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_changing_the_email_needs_the_current_password_and_a_free_address(): void
    {
        $store = $this->openStore();
        $customer = $this->registered($store, ['email_verified_at' => now()]);
        $this->registered($store, ['email' => 'taken@example.com']);
        $h = $this->as($customer);

        $this->putJson('/api/v1/customer/email', ['email' => 'new@example.com', 'current_password' => 'nope'], $h)
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/customer/email', ['email' => 'taken@example.com', 'current_password' => 'correct-horse-99'], $h)
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->putJson('/api/v1/customer/email', ['email' => 'new@example.com', 'current_password' => 'correct-horse-99'], $h)
            ->assertOk()->assertJsonPath('data.email', 'new@example.com')->assertJsonPath('data.email_verified', false);
    }

    public function test_changing_the_password_signs_out_every_other_session(): void
    {
        $store = $this->openStore();
        $customer = $this->registered($store);
        $customer->createToken('phone');
        $customer->createToken('laptop');
        $h = $this->as($customer);

        $this->putJson('/api/v1/customer/password', ['current_password' => 'correct-horse-99', 'password' => 'short', 'password_confirmation' => 'short'], $h)
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/customer/password', ['current_password' => 'wrong', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'], $h)
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/customer/password', ['current_password' => 'correct-horse-99', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'], $h)
            ->assertNoContent();

        $this->assertSame(1, $customer->tokens()->count()); // only the session that made the change
        $this->assertTrue(Hash::check('brand-new-password', $customer->refresh()->password));
        $this->getJson('/api/v1/customer/profile', $h)->assertOk();
    }
}
