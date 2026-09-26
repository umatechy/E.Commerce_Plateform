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

    public $timestamps = false; // created_at only, see migration

    protected $fillable = ['store_id', 'store_theme_id', 'config', 'published_by_user_id'];

    protected function casts(): array
    {
        return ['config' => 'array', 'created_at' => 'datetime'];
    }

    public function storeTheme(): BelongsTo
    {
        return $this->belongsTo(StoreTheme::class);
    }
}
