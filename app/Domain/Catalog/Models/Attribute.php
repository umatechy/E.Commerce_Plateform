<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Attribute extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'attributes';

    protected $fillable = ['store_id', 'name', 'key', 'type'];

    protected function casts(): array
    {
        return ['type' => AttributeType::class];
    }

    /** @return HasMany<AttributeValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }
}
