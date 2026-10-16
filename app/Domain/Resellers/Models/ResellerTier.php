<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase B58 (Module 28 §26–27, §46): a reseller tier — the commercial terms
 * Umar Techy offers a group of resellers. A store's terms are copied when it
 * is attributed (ResellerStore::$terms), so editing a tier changes only
 * stores attributed afterwards.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property ?string $description
 * @property int $client_discount_bp
 * @property int $initial_commission_bp
 * @property int $recurring_commission_bp
 * @property ?int $recurring_months
 * @property int $hold_days
 * @property bool $can_provision
 * @property ?int $max_stores
 * @property int $payout_threshold_minor
 * @property bool $is_active
 */
final class ResellerTier extends Model
{
    use HasPublicId;

    protected $table = 'reseller_tiers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'client_discount_bp' => 'integer', 'initial_commission_bp' => 'integer', 'recurring_commission_bp' => 'integer',
            'recurring_months' => 'integer', 'hold_days' => 'integer', 'can_provision' => 'boolean', 'max_stores' => 'integer',
            'payout_threshold_minor' => 'integer', 'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<Reseller, $this> */
    public function resellers(): HasMany
    {
        return $this->hasMany(Reseller::class);
    }

    /**
     * The terms copied into a store's attribution.
     *
     * @return array{tier: string, client_discount_bp: int, initial_commission_bp: int, recurring_commission_bp: int, recurring_months: ?int, hold_days: int}
     */
    public function terms(): array
    {
        return [
            'tier' => $this->name,
            'client_discount_bp' => $this->client_discount_bp,
            'initial_commission_bp' => $this->initial_commission_bp,
            'recurring_commission_bp' => $this->recurring_commission_bp,
            'recurring_months' => $this->recurring_months,
            'hold_days' => $this->hold_days,
        ];
    }
}
