<?php

declare(strict_types=1);

namespace App\Domain\Theme\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Platform-level catalog — NOT tenant-scoped (no BelongsToTenant), mirrors Package (Phase B2) exactly. */
final class Theme extends Model
{
    protected $table = 'themes';

    protected $fillable = ['key', 'name', 'version', 'status'];

    protected function casts(): array
    {
        return ['status' => ThemeStatus::class];
    }

    public function storeThemes(): HasMany
    {
        return $this->hasMany(StoreTheme::class);
    }
}
