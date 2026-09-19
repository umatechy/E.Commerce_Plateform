<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Platform-level permission catalog (Module 02 §6 — centrally defined,
 * referenced by key, not hard-coded string checks scattered through
 * controllers). Deliberately minimal for B1 scope — only what
 * StoreObserver's default roles (Manager/Staff) actually reference.
 * More permissions are added alongside the modules that need them
 * (products.*, orders.*, etc. already exist here as forward-declared
 * keys so StoreObserver's Manager/Staff role seeding has real rows to
 * attach to; the endpoints those permissions gate are implemented in
 * later phases).
 *
 * Idempotent (updateOrCreate) — safe to re-run, never destructive, per
 * this milestone's "Seeders" requirements.
 */
final class PermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        ['key' => 'roles.view', 'group' => 'roles', 'description' => 'View roles within the store'],
        ['key' => 'roles.manage', 'group' => 'roles', 'description' => 'Create, update, delete roles'],
        ['key' => 'users.view', 'group' => 'users', 'description' => 'View store team members'],
        ['key' => 'users.invite', 'group' => 'users', 'description' => 'Invite new team members'],
        ['key' => 'products.view', 'group' => 'products', 'description' => 'View products'],
        ['key' => 'products.create', 'group' => 'products', 'description' => 'Create products'],
        ['key' => 'products.update', 'group' => 'products', 'description' => 'Update products'],
        ['key' => 'products.delete', 'group' => 'products', 'description' => 'Delete products (Phase B3)'],
        ['key' => 'products.view_cost', 'group' => 'products', 'description' => 'View product cost price — Module 06 §25, never customer-visible (Phase B3)'],
        ['key' => 'categories.manage', 'group' => 'categories', 'description' => 'Create, update, delete categories (Phase B3)'],
        ['key' => 'brands.manage', 'group' => 'brands', 'description' => 'Create, update, delete brands (Phase B3)'],
        ['key' => 'attributes.manage', 'group' => 'attributes', 'description' => 'Create, update, delete attributes (Phase B3)'],
        ['key' => 'inventory.view', 'group' => 'inventory', 'description' => 'View inventory, stock levels, and movement history (Phase B4)'],
        ['key' => 'inventory.adjust', 'group' => 'inventory', 'description' => 'Adjust stock, set opening stock, manage reservations (Phase B4)'],
        ['key' => 'warehouses.manage', 'group' => 'warehouses', 'description' => 'Create, update warehouses (Phase B4)'],
        ['key' => 'orders.view', 'group' => 'orders', 'description' => 'View orders (Module 09 — Phase B5)'],
        ['key' => 'orders.create', 'group' => 'orders', 'description' => 'Create orders on behalf of a customer, e.g. admin/POS orders (Phase B5)'],
        ['key' => 'orders.update', 'group' => 'orders', 'description' => 'Update order status (Module 09 — Phase B5)'],
        ['key' => 'orders.cancel', 'group' => 'orders', 'description' => 'Cancel orders (Phase B5)'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->updateOrCreate(['key' => $permission['key']], $permission);
        }
    }
}
