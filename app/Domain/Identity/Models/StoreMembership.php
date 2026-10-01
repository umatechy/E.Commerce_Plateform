<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's membership in one store (the `store_user` row — Module 02 §4
 * "Account vs Store Membership"). Tenant-scoped, so a store's team
 * endpoints can only ever reach their own store's members.
 */
final class StoreMembership extends Model
{
    use BelongsToTenant;

    protected $table = 'store_user';

    protected $fillable = ['store_id', 'user_id', 'role_id', 'status', 'status_changed_at', 'status_changed_by_user_id'];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_user_id');
    }
}
