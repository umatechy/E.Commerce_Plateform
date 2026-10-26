<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase P2: a store's loyalty rules, one per store. Every rate is the
 * store's own (LoyaltyRules validates them).
 *
 * @property int $id
 * @property int $store_id
 * @property bool $is_enabled
 * @property string $currency
 * @property int $earn_points
 * @property int $earn_spend_minor
 * @property int $redeem_points
 * @property int $redeem_value_minor
 * @property int $min_redeem_points
 * @property int $hold_days
 * @property ?int $expiry_days
 * @property int $birthday_points
 * @property ?int $repeat_every
 * @property int $repeat_points
 * @property list<array{name: string, min_points: int, bonus_percent: int}> $tiers
 * @property ?\Illuminate\Support\Carbon $started_at
 */
final class LoyaltyProgram extends Model
{
    use BelongsToTenant;

    protected $table = 'loyalty_programs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean', 'tiers' => 'array', 'started_at' => 'datetime',
            'earn_points' => 'integer', 'earn_spend_minor' => 'integer', 'redeem_points' => 'integer', 'redeem_value_minor' => 'integer',
            'min_redeem_points' => 'integer', 'hold_days' => 'integer', 'expiry_days' => 'integer', 'birthday_points' => 'integer',
            'repeat_every' => 'integer', 'repeat_points' => 'integer',
        ];
    }

    /** Store credit that a number of points turns into (whole blocks only). */
    public function valueOf(int $points): int
    {
        return intdiv(max(0, $points), max(1, $this->redeem_points)) * $this->redeem_value_minor;
    }

    /** Points an amount earns, with the tier's bonus. */
    public function pointsFor(int $amountMinor, int $bonusPercent = 0): int
    {
        $base = intdiv(max(0, $amountMinor), max(1, $this->earn_spend_minor)) * $this->earn_points;

        return $base + intdiv($base * max(0, $bonusPercent), 100);
    }

    /**
     * The tier for points earned so far: the highest one reached, or null below the first.
     *
     * @return array{name: string, min_points: int, bonus_percent: int}|null
     */
    public function tierFor(int $lifetimePoints): ?array
    {
        $reached = null;
        foreach ($this->sortedTiers() as $tier) {
            if ($lifetimePoints >= $tier['min_points']) {
                $reached = $tier;
            }
        }

        return $reached;
    }

    /** @return array{name: string, min_points: int, bonus_percent: int}|null */
    public function nextTier(int $lifetimePoints): ?array
    {
        foreach ($this->sortedTiers() as $tier) {
            if ($lifetimePoints < $tier['min_points']) {
                return $tier;
            }
        }

        return null;
    }

    /** @return list<array{name: string, min_points: int, bonus_percent: int}> */
    public function sortedTiers(): array
    {
        $tiers = $this->tiers;
        usort($tiers, fn (array $a, array $b) => $a['min_points'] <=> $b['min_points']);

        return $tiers;
    }
}
