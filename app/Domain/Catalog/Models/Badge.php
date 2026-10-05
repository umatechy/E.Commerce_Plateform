<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Phase B43 — Module 06 §36: a badge the store defines ("Handmade", "Eid
 * special") and puts on products. Kept apart from the product's own data;
 * automatic badges (new, sale, bestseller…) are not rows — ProductBadges
 * computes them from the store's badge settings.
 *
 * @property int $id
 * @property string $label
 * @property string $tone
 * @property int $priority
 * @property bool $is_active
 */
final class Badge extends Model
{
    use BelongsToTenant;

    /** Theme colours a badge may use (Module 17 §8 badge colours). */
    public const TONES = ['accent', 'success', 'warning', 'danger', 'neutral'];

    protected $fillable = ['label', 'tone', 'priority', 'is_active'];

    protected $attributes = ['tone' => 'accent', 'priority' => 50, 'is_active' => true];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_badge');
    }
}
