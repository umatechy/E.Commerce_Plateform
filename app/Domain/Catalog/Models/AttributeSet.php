<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Phase B41 — Module 07 §44: a reusable list of attributes ("Laptops":
 * processor, RAM, storage…) that can be applied to a category.
 *
 * @property int $id
 * @property string $name
 */
final class AttributeSet extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name'];

    /** @return BelongsToMany<Attribute, $this> in their order */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'attribute_set_items')->withPivot('position')->orderByPivot('position');
    }
}
