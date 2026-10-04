<?php

declare(strict_types=1);

namespace App\Domain\StoreCredit\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B35 — what is left of one credit, and when it expires (null = never).
 * Written only by StoreCreditService; the ledger entries stay the record.
 *
 * @property int $id
 * @property int $account_id
 * @property int|null $entry_id
 * @property int $amount_minor
 * @property int $remaining_minor
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
final class StoreCreditLot extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'remaining_minor' => 'integer', 'expires_at' => 'datetime'];
    }
}
