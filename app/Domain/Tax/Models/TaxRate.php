<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B46 — owner decision 1, Module 29 §57 (rate, jurisdiction, category):
 * a rate the store set for one tax class, in a country (or any country) and
 * optionally one region of it, valid between two dates. Basis points:
 * 1 650 = 16.5 %. Every rate that matches an address applies (a country rate
 * and a region rate add up). Nothing is seeded: the store enters its own
 * rates once they are confirmed.
 *
 * @property int $id
 * @property int $tax_class_id
 * @property string $name
 * @property ?string $country
 * @property ?string $region
 * @property int $rate_bps
 * @property bool $is_active
 * @property ?\Illuminate\Support\Carbon $starts_on
 * @property ?\Illuminate\Support\Carbon $ends_on
 */
final class TaxRate extends Model
{
    use BelongsToTenant;

    public const MAX_BPS = 10000; // 100 %

    protected $fillable = ['tax_class_id', 'name', 'country', 'region', 'rate_bps', 'is_active', 'starts_on', 'ends_on'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['rate_bps' => 'integer', 'is_active' => 'boolean', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    /** @return BelongsTo<TaxClass, $this> */
    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }
}
