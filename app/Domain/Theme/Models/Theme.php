<?php

declare(strict_types=1);

namespace App\Domain\Theme\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Platform-level catalog — NOT tenant-scoped (no BelongsToTenant), mirrors Package (Phase B2) exactly. */
final class Theme extends Model
{
    protected $table = 'themes';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected $fillable = ['key', 'name', 'description', 'tier', 'version', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => ThemeStatus::class];
    }

    /** @return HasMany<StoreTheme, $this> */
    public function storeThemes(): HasMany
    {
        return $this->hasMany(StoreTheme::class);
    }
}
