<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Hash;

/** Shared set-up for the Phase B25 customer account tests. */
trait InteractsWithCustomerAccounts
{
    protected function openStore(): Store
    {
        $store = Store::factory()->create(['status' => 'active']);
        $this->entitle($store, ['orders.basic', 'wishlist.basic', 'payment.cod', 'shipping.basic']);
        app(TenantContext::class)->resolveToStore($store->id);

        return $store;
    }

    /**
     * Each simulated request authenticates afresh, as a real one does:
     * Laravel caches the resolved user on its guards between requests
     * inside one test, so a second customer's token would otherwise be
     * answered as the first customer.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function registered(Store $store, array $attributes = []): Customer
    {
        return Customer::factory()->for($store)->create(['password' => Hash::make('correct-horse-99'), ...$attributes]);
    }

    /** @return array<string, string> bearer-token headers for the customer API */
    protected function as(Customer $customer): array
    {
        return ['Authorization' => 'Bearer '.$customer->createToken('t')->plainTextToken];
    }
}
