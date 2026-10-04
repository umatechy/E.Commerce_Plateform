<?php

declare(strict_types=1);

namespace App\Domain\StoreCredit\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Module 09 §52: one line of the store credit ledger. Written once by
 * StoreCreditService and never changed or removed: a correction is a
 * new entry.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property int $account_id
 * @property int $customer_id
 * @property StoreCreditEntryType $type
 * @property int $amount_minor
 * @property int $balance_after_minor
 * @property string $currency
 * @property ?string $reference_type
 * @property ?int $reference_id
 * @property ?string $note
 * @property ?int $actor_user_id
 * @property \Illuminate\Support\Carbon $created_at
 */
final class StoreCreditEntry extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'store_credit_entries';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['type' => StoreCreditEntryType::class, 'amount_minor' => 'integer', 'balance_after_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        // An immutable ledger, like the stock movements and the audit trail.
        self::updating(fn () => throw new LogicException('Store credit entries are never changed. Record a new entry instead.'));
        self::deleting(fn () => throw new LogicException('Store credit entries are never deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
