<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ShippingZone extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'shipping_zones';

    protected $fillable = ['store_id', 'name', 'country', 'province', 'city', 'postal_code', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return HasMany<ShippingRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class);
    }

    /**
     * Module 13 §9 "Zone Priority" — a numeric specificity score used
     * by ShippingRateService to pick the single best-matching zone
     * deterministically (higher = more specific = wins).
     */
    public function specificity(): int
    {
        return ($this->postal_code !== null ? 8 : 0)
            + ($this->city !== null ? 4 : 0)
            + ($this->province !== null ? 2 : 0)
            + ($this->country !== null ? 1 : 0);
    }

    public function matches(array $destination): bool
    {
        if ($this->postal_code !== null && ($destination['postal_code'] ?? null) !== $this->postal_code) {
            return false;
        }
        if ($this->city !== null && strcasecmp($this->city, (string) ($destination['city'] ?? '')) !== 0) {
            return false;
        }
        if ($this->province !== null && strcasecmp($this->province, (string) ($destination['province'] ?? '')) !== 0) {
            return false;
        }
        if ($this->country !== null && strcasecmp($this->country, (string) ($destination['country'] ?? '')) !== 0) {
            return false;
        }

        return true;
    }
}
