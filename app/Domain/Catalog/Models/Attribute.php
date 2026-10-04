<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 07 §27–37: a reusable product property. Phase B41 added a group
 * (§28), a unit for numbers (§33), active/inactive (§75) and an order.
 *
 * @property int $id
 * @property string $name
 * @property string $key
 * @property AttributeType $type
 * @property ?string $group
 * @property ?string $unit
 * @property bool $is_active
 * @property int $sort_order
 */
final class Attribute extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'attributes';

    protected $fillable = ['store_id', 'name', 'key', 'type', 'group', 'unit', 'is_active', 'sort_order'];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['type' => AttributeType::class, 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return HasMany<AttributeValue, $this> in their set order (§79) */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order')->orderBy('id');
    }
}
