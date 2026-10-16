<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B58 (Module 28 §39, §69): a signed correction to what a reseller is
 * owed — a refund taken back after payment (automatic) or a correction by
 * Umar Techy with a reason. Settled with the next payout; never edited.
 *
 * @property int $id
 * @property string $public_id
 * @property int $reseller_id
 * @property ?int $reseller_commission_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $reason
 * @property ?int $created_by
 * @property ?int $reseller_payout_id
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ResellerAdjustment extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'reseller_adjustments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
