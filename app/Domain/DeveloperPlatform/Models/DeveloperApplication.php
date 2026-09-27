<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** "Dumb" — ApplicationService is the only writer of `status`. */
final class DeveloperApplication extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'developer_applications';

    protected $fillable = ['store_id', 'name', 'status', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['status' => ApplicationStatus::class];
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function webhookSubscriptions(): HasMany
    {
        return $this->hasMany(WebhookSubscription::class);
    }
}
