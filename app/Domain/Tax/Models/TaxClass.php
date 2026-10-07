<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase B46 — owner decision 1, Module 11 §28 "product tax category": a
 * group of products taxed alike. The store names its classes; one is the
 * default for products without a class.
 *
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property bool $is_default
 */
final class TaxClass extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'description', 'is_default'];

    protected $attributes = ['is_default' => false];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** @return HasMany<TaxRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }
}
