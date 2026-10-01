<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation to join a store's team (Module 02 §18): expiring,
 * single-use, tenant- and role-scoped, revocable. Only the token's hash
 * is stored. StoreTeamService is the only writer.
 */
final class StoreInvitation extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'store_invitations';

    protected $fillable = [
        'public_id', 'store_id', 'email', 'pending_email', 'role_id', 'token_hash', 'status', 'expires_at',
        'invited_by_user_id', 'accepted_at', 'accepted_user_id', 'revoked_at', 'revoked_by_user_id',
    ];

    protected $hidden = ['token_hash', 'pending_email'];

    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isOpen(): bool
    {
        return $this->status === InvitationStatus::Pending && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
