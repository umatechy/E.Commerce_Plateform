<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Module 34 (Phase B26) — stores created before B26 got their default
 * Manager and Staff roles without the support permissions (StoreObserver
 * grants them only at store creation). This gives existing system roles
 * the same support access new stores get, so their teams can use the
 * inbox without an owner editing every role. Additive and idempotent;
 * custom roles are left for the owner to decide.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> system role slug => permission keys */
    private const GRANTS = [
        'manager' => ['support.view', 'support.reply', 'support.manage'],
        'staff' => ['support.view', 'support.reply'],
    ];

    private const PERMISSIONS = [
        'support.view' => 'Read the store\'s support inbox (Module 34 — Phase B26)',
        'support.reply' => 'Reply to support requests, add internal notes, change their status (Phase B26)',
        'support.manage' => 'Assign, re-prioritise and re-categorise support requests (Phase B26)',
        'support.platform' => 'Contact the platform\'s support team on the store\'s behalf (Phase B26)',
    ];

    public function up(): void
    {
        // The permissions may not be seeded yet on an existing installation.
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $key, string $description) => ['key' => $key, 'group' => 'support', 'description' => $description],
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
        $roleIds = DB::table('roles')->where('is_system', true)->whereIn('slug', array_keys(self::GRANTS))->pluck('id');
        $permissionIds = DB::table('permissions')->whereIn('key', ['support.view', 'support.reply', 'support.manage'])->pluck('id');

        DB::table('permission_role')->whereIn('role_id', $roleIds)->whereIn('permission_id', $permissionIds)->delete();
    }
};
