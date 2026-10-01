<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase G1 (Module 02 §6) — stores created before G1 have three system
 * roles (owner, manager, staff). New stores get seven (SystemRoles). This
 * adds the four missing system roles to every existing store, with the
 * same permissions, and adds the new users.manage permission.
 *
 * The definitions are copied here on purpose: a migration must keep doing
 * what it did when it shipped, even if SystemRoles changes later.
 *
 * A store that already has a custom role with one of these slugs keeps it
 * untouched and simply does not receive that system role; it can create
 * the equivalent role itself. Additive and idempotent.
 */
return new class extends Migration
{
    /** @var array<string, array{name: string, permissions: list<string>}> */
    private const ROLES = [
        'administrator' => ['name' => 'Administrator', 'permissions' => [
            'roles.view', 'users.view', 'users.invite', 'users.manage',
            'products.view', 'products.create', 'products.update', 'products.delete', 'products.view_cost',
            'categories.manage', 'brands.manage', 'attributes.manage',
            'inventory.view', 'inventory.adjust', 'warehouses.manage',
            'orders.view', 'orders.create', 'orders.update', 'orders.cancel',
            'payments.view', 'payments.manage', 'payments.refund',
            'shipments.view', 'shipments.fulfill', 'shipping_config.manage',
            'promotions.view', 'promotions.manage', 'marketing.view', 'marketing.manage',
            'notifications.view', 'notifications.manage',
            'analytics.view', 'analytics.export', 'analytics.financial',
            'seo.view', 'seo.manage', 'domains.view', 'domains.manage',
            'theme.view', 'theme.manage', 'theme.publish', 'settings.view', 'settings.manage',
            'developer_platform.view', 'store_health.view', 'backups.view',
            'support.view', 'support.reply', 'support.manage',
            'storefront.manage', 'audit.view', 'billing.view',
        ]],
        'order-manager' => ['name' => 'Order Manager', 'permissions' => [
            'orders.view', 'orders.create', 'orders.update', 'orders.cancel',
            'payments.view', 'payments.manage', 'shipments.view', 'shipments.fulfill',
            'products.view', 'inventory.view', 'support.view', 'support.reply',
        ]],
        'inventory-manager' => ['name' => 'Inventory Manager', 'permissions' => [
            'products.view', 'inventory.view', 'inventory.adjust', 'warehouses.manage',
            'orders.view', 'shipments.view',
        ]],
        'content-marketing' => ['name' => 'Content & Marketing', 'permissions' => [
            'products.view', 'products.create', 'products.update',
            'categories.manage', 'brands.manage', 'attributes.manage',
            'promotions.view', 'promotions.manage', 'marketing.view', 'marketing.manage',
            'seo.view', 'seo.manage', 'theme.view', 'theme.manage', 'analytics.view',
        ]],
    ];

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'key' => 'users.manage',
            'group' => 'users',
            'description' => 'Change team members\' roles, suspend, reactivate or remove them (Module 02 §19 — Phase G1)',
        ]);

        foreach (self::ROLES as $slug => $definition) {
            $permissionIds = DB::table('permissions')->whereIn('key', $definition['permissions'])->pluck('id');

            DB::table('stores')->orderBy('id')->chunkById(500, function ($stores) use ($slug, $definition, $permissionIds) {
                foreach ($stores as $store) {
                    $exists = DB::table('roles')->where('store_id', $store->id)->where('slug', $slug)->exists();

                    if ($exists) {
                        continue; // already seeded, or a custom role with the same slug (left as it is)
                    }

                    $roleId = DB::table('roles')->insertGetId([
                        'store_id' => $store->id,
                        'name' => $definition['name'],
                        'slug' => $slug,
                        'is_system' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('permission_role')->insertOrIgnore(
                        $permissionIds->map(fn ($id) => ['role_id' => $roleId, 'permission_id' => $id])->all(),
                    );
                }
            });
        }
    }

    public function down(): void
    {
        // Only unassigned roles are removed; a role someone holds stays.
        $roleIds = DB::table('roles')->where('is_system', true)->whereIn('slug', array_keys(self::ROLES))
            ->whereNotExists(fn ($q) => $q->from('store_user')->whereColumn('store_user.role_id', 'roles.id'))
            ->whereNotExists(fn ($q) => $q->from('store_invitations')->whereColumn('store_invitations.role_id', 'roles.id'))
            ->pluck('id');

        DB::table('permission_role')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();
    }
};
