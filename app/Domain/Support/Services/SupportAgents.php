<?php

declare(strict_types=1);

namespace App\Domain\Support\Services;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may be assigned a ticket: on the store desk, the store's active
 * members who can answer support (the Owner, or a role with
 * support.reply / support.manage); on the platform desk, active platform
 * staff.
 */
final class SupportAgents
{
    /** @return Collection<int, User> */
    public function forStore(int $storeId): Collection
    {
        $userIds = DB::table('store_user')
            ->join('roles', 'roles.id', '=', 'store_user.role_id')
            ->where('store_user.store_id', $storeId)
            ->where('store_user.status', 'active')
            ->where(fn ($q) => $q->where('roles.slug', 'owner')->orWhereExists(fn ($p) => $p->selectRaw('1')
                ->from('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->whereColumn('permission_role.role_id', 'roles.id')
                ->whereIn('permissions.key', ['support.reply', 'support.manage'])))
            ->pluck('store_user.user_id');

        return User::query()->whereIn('id', $userIds)->where('is_active', true)->orderBy('name')->get();
    }

    /** @return Collection<int, User> */
    public function forPlatform(): Collection
    {
        return User::query()->whereNotNull('platform_role')->where('is_active', true)->orderBy('name')->get();
    }
}
