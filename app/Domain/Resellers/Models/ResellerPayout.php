<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B58 (Module 28 §33–38): money Umar Techy paid a reseller. Umar Techy
 * pays outside the platform and records the bank or wallet reference.
 *
 * @property int $id
 * @property string $public_id
 * @property int $reseller_id
 * @property string $status
 * @property int $amount_minor
 * @property string $currency
 * @property ?string $method
 * @property ?string $reference
 * @property ?string $note
 * @property ?\Illuminate\Support\Carbon $paid_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Reseller $reseller
 */
final class ResellerPayout extends Model
{
    use HasPublicId;

    protected $table = 'reseller_payouts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'paid_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Reseller, $this> */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }
}
