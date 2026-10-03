<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase B32 (gap G7): the customer permissions. Stores created before
 * B32 got their system roles without them (StoreObserver grants
 * permissions only at store creation), so the same grants are given to
 * the existing system roles here, matching SystemRoles. Additive and
 * idempotent; custom roles are left for the owner to decide.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'customers.view' => 'View customers, their orders, addresses, tags, groups, notes and activity (Module 10 — Phase B32)',
        'customers.manage' => 'Add customers; edit groups, tags and notes; block, unblock, archive and restore customers (Phase B32)',
        'customers.export' => 'Export the customer list with contact details — personal data (Phase B32)',
        'customers.import' => 'Import customers from a CSV file (Phase B32)',
    ];

    /** @var array<string, list<string>> system role slug => permission keys */
    private const GRANTS = [
        'administrator' => ['customers.view', 'customers.manage', 'customers.export', 'customers.import'],
        'manager' => ['customers.view', 'customers.manage'],
        'order-manager' => ['customers.view'],
        'content-marketing' => ['customers.view'],
    ];

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $key, string $description) => ['key' => $key, 'group' => 'customers', 'description' => $description],
            array_keys(self::PERMISSIONS), self::PERMISSIONS,
        ));

        foreach (self::GRANTS as $slug => $keys) {
            $permissionIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id');

            DB::table('roles')->where('is_system', true)->where('slug', $slug)->orderBy('id')
                ->chunkById(500, function ($roles) use ($permissionIds) {
                    DB::table('permission_role')->insertOrIgnore(
                        $roles->flatMap(fn ($role) => $permissionIds->map(fn ($id) => ['role_id' => $role->id, 'permission_id' => $id]))->all(),
                    );
                });
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
