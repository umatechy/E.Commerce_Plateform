<?php

declare(strict_types=1);

namespace App\Domain\Theme\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** "Dumb" like every other core-state model — ThemeService is the only writer of `published_config`/`published_at`. */
final class StoreTheme extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'store_themes';

    protected $fillable = ['store_id', 'theme_id', 'draft_config', 'published_config', 'custom_css', 'published_at'];

    protected function casts(): array
    {
        return ['draft_config' => 'array', 'published_config' => 'array', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<Theme, $this> */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    /** @return HasMany<StoreThemePublication, $this> */
    public function publications(): HasMany
    {
        return $this->hasMany(StoreThemePublication::class);
    }

    public function isPublished(): bool
    {
        return $this->published_config !== null;
    }
}
