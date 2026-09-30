<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Packages\Models\Package;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 29 — a Package's price for one interval and currency. Platform
 * catalog, never tenant-scoped (like Package).
 *
 * @property int $id
 * @property string $public_id
 * @property int $package_id
 * @property BillingInterval $billing_interval
 * @property string $currency
 * @property int $amount_minor
 * @property bool $is_active
 */
final class PackagePrice extends Model
{
    use HasPublicId;

    protected $table = 'package_prices';

    protected $fillable = ['package_id', 'billing_interval', 'currency', 'amount_minor', 'is_active'];

    protected function casts(): array
    {
        return [
            'billing_interval' => BillingInterval::class,
            'amount_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
