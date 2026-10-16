<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Models;

use App\Domain\Identity\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase B58 (Module 28 §5.3, §6, §92): a platform reseller — a business that
 * sells Umar Techy to its clients. Its identity is separate from the user
 * account it signs in with (§6) and from the stores it brings (§29).
 *
 * @property int $id
 * @property string $public_id
 * @property ?int $user_id
 * @property ?int $reseller_tier_id
 * @property string $code
 * @property string $business_name
 * @property string $contact_name
 * @property string $email
 * @property ?string $phone
 * @property ?string $country
 * @property ?string $website
 * @property string $status
 * @property ?string $status_reason
 * @property string $source
 * @property ?string $application_note
 * @property ?string $invitation_token_hash
 * @property ?\Illuminate\Support\Carbon $invitation_expires_at
 * @property ?\Illuminate\Support\Carbon $terms_accepted_at
 * @property ?string $payout_method
 * @property ?array<string, string|null> $payout_details
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?\Illuminate\Support\Carbon $terminated_at
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property-read ?ResellerTier $tier
 * @property-read ?User $user
 */
final class Reseller extends Model
{
    use HasPublicId;

    public const STATUSES = ['invited', 'pending', 'active', 'paused', 'rejected', 'terminated'];

    protected $table = 'resellers';

    protected $guarded = ['id'];

    protected $hidden = ['payout_details', 'invitation_token_hash'];

    protected function casts(): array
    {
        return [
            'payout_details' => 'encrypted:array', 'invitation_expires_at' => 'datetime', 'terms_accepted_at' => 'datetime',
            'approved_at' => 'datetime', 'terminated_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<ResellerTier, $this> */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(ResellerTier::class, 'reseller_tier_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ResellerStore, $this> */
    public function stores(): HasMany
    {
        return $this->hasMany(ResellerStore::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
