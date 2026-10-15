<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B56 (Module 27 §17–19): one release of an app — what it may read,
 * which events it receives, the settings form it shows the store, what it
 * does with the data, and its review.
 */
final class MarketplaceAppVersion extends Model
{
    protected $table = 'marketplace_app_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'events' => 'array', 'config_schema' => 'array', 'review' => 'array', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<MarketplaceApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(MarketplaceApp::class, 'marketplace_app_id');
    }
}
