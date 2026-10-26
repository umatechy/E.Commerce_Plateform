<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Phase P2 (Module 15 §45): one line of the points ledger. Written once by
 * LoyaltyLedger and never changed or removed: a correction is a new entry.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property int $account_id
 * @property int $customer_id
 * @property LoyaltyEntryType $type
 * @property int $points
 * @property int $balance_after
 * @property ?string $reference_type
 * @property ?int $reference_id
 * @property ?string $note
 * @property ?int $actor_user_id
 * @property \Illuminate\Support\Carbon $created_at
 */
final class LoyaltyEntry extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'loyalty_entries';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['type' => LoyaltyEntryType::class, 'points' => 'integer', 'balance_after' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Loyalty entries are never changed. Record a new entry instead.'));
        self::deleting(fn () => throw new LogicException('Loyalty entries are never deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
