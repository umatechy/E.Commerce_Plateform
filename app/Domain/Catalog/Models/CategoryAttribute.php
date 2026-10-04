<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B41 — Module 07 §18–19, §45: an attribute that applies to a
 * category — required for its products or not, a customer filter or not.
 *
 * @property int $id
 * @property int $category_id
 * @property int $attribute_id
 * @property bool $is_required
 * @property bool $is_filter
 * @property int $position
 */
final class CategoryAttribute extends Model
{
    use BelongsToTenant;

    protected $fillable = ['category_id', 'attribute_id', 'is_required', 'is_filter', 'position'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_filter' => 'boolean', 'position' => 'integer'];
    }

    /** @return BelongsTo<Attribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
