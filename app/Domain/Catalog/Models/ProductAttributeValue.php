<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B41 — Module 07 §41: one specification of a product. Exactly one
 * of the value columns is used, by the attribute's type: a chosen value
 * (select, colour; one row per value for multi-select), a number, yes/no,
 * or a text. Written only by ProductSpecifications.
 *
 * @property int $id
 * @property int $product_id
 * @property int $attribute_id
 * @property ?int $attribute_value_id
 * @property ?string $number_value
 * @property ?bool $bool_value
 * @property ?string $text_value
 */
final class ProductAttributeValue extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['product_id', 'attribute_id', 'attribute_value_id', 'number_value', 'bool_value', 'text_value'];

    protected function casts(): array
    {
        return ['bool_value' => 'boolean'];
    }

    /** @return BelongsTo<Attribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    /** @return BelongsTo<AttributeValue, $this> */
    public function choice(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class, 'attribute_value_id');
    }
}
