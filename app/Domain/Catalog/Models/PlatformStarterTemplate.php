<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase B45 follow-up: a starter template's platform record — a built-in
 * template's availability, or a template staff saved from a store.
 * Platform data: deliberately NOT BelongsToTenant.
 *
 * @property int $id
 * @property string $key
 * @property string $source built_in | store
 * @property ?string $name
 * @property ?string $summary
 * @property ?string $business_category
 * @property int $version
 * @property array<string, mixed>|null $definition
 * @property bool $is_active
 * @property bool $offered_to_stores
 * @property ?int $source_store_id
 * @property ?int $created_by_user_id
 * @property \Illuminate\Support\Carbon $created_at
 */
final class PlatformStarterTemplate extends Model
{
    public const BUILT_IN = 'built_in';
    public const FROM_STORE = 'store';

    protected $fillable = ['key', 'source', 'name', 'summary', 'business_category', 'version', 'definition', 'is_active', 'offered_to_stores', 'source_store_id', 'created_by_user_id'];

    protected $attributes = ['is_active' => true, 'offered_to_stores' => true, 'version' => 1];

    protected function casts(): array
    {
        return ['definition' => 'array', 'is_active' => 'boolean', 'offered_to_stores' => 'boolean', 'version' => 'integer'];
    }
}
