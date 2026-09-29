<?php

declare(strict_types=1);

namespace App\Domain\Theme\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only — never updated/deleted by application code (Module 17 §20 "Theme Rollback" source). */
final class StoreThemePublication extends Model
{
    use BelongsToTenant;

    protected $table = 'store_theme_publications';

    // created_at only (see migration). UPDATED_AT = null keeps Eloquent
    // filling created_at itself, so a just-created row exposes it
    // without a refresh (resources call ->toIso8601String() on it).
    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'store_theme_id', 'config', 'published_by_user_id'];

    protected function casts(): array
    {
        return ['config' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<StoreTheme, $this> */
    public function storeTheme(): BelongsTo
    {
        return $this->belongsTo(StoreTheme::class);
    }
}
