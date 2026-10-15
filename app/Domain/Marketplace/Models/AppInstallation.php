<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B56 (Module 27 §10–12, §23–25): one store's installation of an
 * app. Store-scoped. The app acts only through the store's developer
 * application linked here (its scoped API key and webhook subscription);
 * the store's settings for the app are encrypted, secrets included.
 */
final class AppInstallation extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'app_installations';

    protected $guarded = ['id'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'granted_scopes' => 'array', 'granted_events' => 'array', 'config' => 'encrypted:array',
            'installed_at' => 'datetime', 'connected_at' => 'datetime', 'uninstalled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<MarketplaceApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(MarketplaceApp::class, 'marketplace_app_id');
    }

    /** @return BelongsTo<MarketplaceAppVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAppVersion::class, 'marketplace_app_version_id');
    }

    /** @return BelongsTo<DeveloperApplication, $this> */
    public function developerApplication(): BelongsTo
    {
        return $this->belongsTo(DeveloperApplication::class);
    }
}
