<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Domain\CustomerAccount\Models\CustomerAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase B25 — the address book: ownership, one default, limits and validation. */
final class CustomerAddressTest extends TestCase
{
    use InteractsWithCustomerAccounts, RefreshDatabase;

    private function address(array $overrides = []): array
    {
        return ['label' => 'Home', 'name' => 'Amna Rauf', 'phone' => '+92 300 1234567', 'line1' => '12 Mall Road', 'city' => 'Lahore', 'postal_code' => '54000', 'country' => 'pk', ...$overrides];
    }

    public function test_the_first_address_becomes_the_default_and_there_is_always_one(): void
    {
        $store = $this->openStore();
        $h = $this->as($this->registered($store));

        $home = $this->postJson('/api/v1/customer/addresses', $this->address(), $h)->assertCreated()
            ->assertJsonPath('data.is_default', true)->assertJsonPath('data.country', 'PK')->json('data.id');
        $office = $this->postJson('/api/v1/customer/addresses', $this->address(['label' => 'Office', 'is_default' => true]), $h)->assertCreated()->json('data.id');

        $this->getJson('/api/v1/customer/addresses', $h)->assertOk()
            ->assertJsonPath('data.0.id', $office)->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.1.is_default', false);

        $this->postJson("/api/v1/customer/addresses/{$home}/default", [], $h)->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertSame(1, CustomerAddress::query()->where('is_default', true)->count());

        // Deleting the default promotes another.
        $this->deleteJson("/api/v1/customer/addresses/{$home}", [], $h)->assertNoContent();
        $this->assertTrue(CustomerAddress::query()->where('public_id', $office)->value('is_default'));
    }

    public function test_addresses_are_edited_and_validated(): void
    {
        $store = $this->openStore();
        $h = $this->as($this->registered($store));
        $id = $this->postJson('/api/v1/customer/addresses', $this->address(), $h)->json('data.id');

        $this->patchJson("/api/v1/customer/addresses/{$id}", ['city' => 'Islamabad'], $h)->assertOk()->assertJsonPath('data.city', 'Islamabad');
        $this->postJson('/api/v1/customer/addresses', $this->address(['country' => 'Pakistan', 'line1' => '', 'phone' => '<script>']), $h)
            ->assertStatus(422)->assertJsonValidationErrors(['country', 'line1', 'phone']);
    }

    public function test_nobody_else_can_see_or_change_an_address(): void
    {
        $store = $this->openStore();
        $owner = $this->as($this->registered($store));
        $other = $this->as($this->registered($store));
        $id = $this->postJson('/api/v1/customer/addresses', $this->address(), $owner)->json('data.id');

        $this->getJson('/api/v1/customer/addresses', $other)->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/customer/addresses/{$id}", ['city' => 'X'], $other)->assertNotFound();
        $this->deleteJson("/api/v1/customer/addresses/{$id}", [], $other)->assertNotFound();

        $foreignStore = $this->openStore();
        $this->deleteJson("/api/v1/customer/addresses/{$id}", [], $this->as($this->registered($foreignStore)))->assertNotFound();
        $this->getJson('/api/v1/customer/addresses')->assertUnauthorized();
    }

    public function test_the_address_book_is_capped(): void
    {
        $store = $this->openStore();
        $customer = $this->registered($store);
        foreach (range(1, 20) as $i) {
            CustomerAddress::query()->create(['store_id' => $store->id, 'customer_id' => $customer->id, 'name' => 'A', 'line1' => "{$i} St", 'city' => 'Lahore', 'country' => 'PK']);
        }

        $this->postJson('/api/v1/customer/addresses', $this->address(), $this->as($customer))->assertStatus(422)->assertJsonValidationErrors('address');
    }
}
