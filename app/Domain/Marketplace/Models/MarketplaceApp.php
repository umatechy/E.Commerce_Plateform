<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase B56 (Module 27 §8): an app in the platform's registry. Platform
 * data: no store owns it. Its client secret is kept only as a hash and
 * shown once, when it is made.
 *
 * @property int $active_installations from withCount() in the Super Admin list
 */
final class MarketplaceApp extends Model
{
    use HasPublicId;

    protected $table = 'marketplace_apps';

    protected $guarded = ['id'];

    protected $hidden = ['client_secret_hash'];

    protected function casts(): array
    {
        return ['redirect_uris' => 'array', 'required_features' => 'array', 'suspended_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<MarketplaceAppVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(MarketplaceAppVersion::class)->orderByDesc('id');
    }

    /** @return BelongsTo<MarketplaceAppVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAppVersion::class, 'current_version_id');
    }

    /** @return HasMany<AppInstallation, $this> */
    public function installations(): HasMany
    {
        return $this->hasMany(AppInstallation::class);
    }
}
