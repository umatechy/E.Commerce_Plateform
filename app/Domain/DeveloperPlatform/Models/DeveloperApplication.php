<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** "Dumb" — ApplicationService is the only writer of `status`. */
final class DeveloperApplication extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'developer_applications';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected $fillable = ['store_id', 'name', 'status', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['status' => ApplicationStatus::class];
    }

    /** @return HasMany<ApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /** @return HasMany<WebhookSubscription, $this> */
    public function webhookSubscriptions(): HasMany
    {
        return $this->hasMany(WebhookSubscription::class);
    }
}
