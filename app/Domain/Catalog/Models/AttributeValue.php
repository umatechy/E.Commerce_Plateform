<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately does NOT use BelongsToTenant — tenant isolation is
 * inherited from attributes.store_id (same pattern as permission_role
 * in Phase B1: a pivot/child whose parent is already tenant-scoped
 * does not need its own store_id column or its own global scope).
 * Always reach values through a tenant-scoped Attribute.
 *
 * @property int $id
 * @property int $attribute_id
 * @property string $value
 * @property string $normalized_value
 * @property ?string $slug
 * @property ?string $color_code
 * @property bool $is_active
 * @property int $sort_order
 */
final class AttributeValue extends Model
{
    protected $table = 'attribute_values';

    protected $fillable = ['attribute_id', 'value', 'normalized_value', 'slug', 'color_code', 'is_active', 'sort_order'];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Attribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
