<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase B26 — stores created before Module 34 get the same support access as new ones. */
final class SupportPermissionsBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function supportKeys(Role $role): array
    {
        return $role->permissions()->where('key', 'like', 'support.%')->orderBy('key')->pluck('key')->all();
    }

    public function test_existing_default_roles_get_support_permissions_and_custom_roles_do_not(): void
    {
        $store = Store::factory()->create();
        $roles = Role::query()->withoutTenantScope()->where('store_id', $store->id)->get()->keyBy('slug');
        $custom = Role::factory()->for($store)->create(['slug' => 'packer', 'is_system' => false]);
        // As before B26: no support permissions anywhere.
        DB::table('permission_role')->whereIn('permission_id', Permission::query()->where('key', 'like', 'support.%')->pluck('id'))->delete();

        $migration = require database_path('migrations/2028_01_01_000002_grant_support_permissions_to_existing_roles.php');
        $migration->up();
        $migration->up(); // safe to run twice

        $this->assertSame(['support.manage', 'support.reply', 'support.view'], $this->supportKeys($roles['manager']));
        $this->assertSame(['support.reply', 'support.view'], $this->supportKeys($roles['staff']));
        $this->assertSame([], $this->supportKeys($roles['owner'])); // the Owner needs no rows
        $this->assertSame([], $this->supportKeys($custom));
        $this->assertSame(4, Permission::query()->where('key', 'like', 'support.%')->count());

        $migration->down();
        $this->assertSame([], $this->supportKeys($roles['manager']));
    }
}
