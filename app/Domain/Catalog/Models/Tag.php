<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Phase B39 — Module 06 §35: a lightweight, tenant-scoped product label
 * ("Handmade", "Eid", "Organic"). The slug is unique per store.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 */
final class Tag extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'slug'];

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
