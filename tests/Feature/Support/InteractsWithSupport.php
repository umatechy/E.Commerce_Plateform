<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domain\Events\Jobs\ConsumeOutboxEventJob;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Shared set-up for the Module 34 (Phase B26) support tests. */
trait InteractsWithSupport
{
    protected function openStore(): Store
    {
        $store = Store::factory()->create(['status' => 'active']);
        $this->entitle($store, ['orders.basic']);
        app(TenantContext::class)->resolveToStore($store->id);

        return $store;
    }

    protected function owner(Store $store): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return $user;
    }

    /** @param list<string> $permissions */
    protected function staff(Store $store, array $permissions): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'custom-'.uniqid()]);
        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['group' => 'support', 'description' => 'x']);
            DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    protected function customer(Store $store, array $attributes = []): Customer
    {
        return Customer::factory()->for($store)->create(['password' => Hash::make('correct-horse-99'), ...$attributes]);
    }

    /** @return array<string, string> */
    protected function as(Customer $customer): array
    {
        return ['Authorization' => 'Bearer '.$customer->createToken('t')->plainTextToken];
    }

    /** Runs the outbox consumer for every pending event (emails are sent from there). */
    protected function deliverEvents(): void
    {
        OutboxEvent::query()->withoutTenantScope()->where('status', 'pending')->orderBy('id')->pluck('id')
            ->each(fn (int $id) => app()->call([new ConsumeOutboxEventJob($id), 'handle']));
    }

    /**
     * Tokens are re-read on every simulated request (Laravel caches the
     * resolved user on each guard inside one test). The session (`web`)
     * guard is kept, so staff signed in with actingAs() stay signed in.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->guard('customer')->forgetUser();
        $this->app['auth']->guard('sanctum')->forgetUser();

        // A bearer-token request carries no staff session in real life.
        if (isset($server['HTTP_AUTHORIZATION'])) {
            $this->app['auth']->guard('web')->forgetUser();
        }

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            // A customer request switches the default guard (and the
            // auth.defaults.guard config value) to `customer`; the next
            // request starts from the app's default again, so a later
            // actingAs() signs staff in on the right guard.
            $this->app['auth']->shouldUse('web');
        }
    }
}
