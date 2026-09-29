<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One persisted store-health report (Module 24). Written only by
 * StoreHealthService::snapshot(); the Super Admin overview reads it
 * across stores via withoutTenantScope().
 */
final class StoreHealthSnapshot extends Model
{
    use BelongsToTenant;

    protected $table = 'store_health_snapshots';

    // Append-only: created_at only (see migration).
    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'overall_status', 'checks'];

    protected function casts(): array
    {
        return [
            'overall_status' => HealthStatus::class,
            'checks' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
