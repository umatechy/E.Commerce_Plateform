<?php

declare(strict_types=1);

namespace App\Domain\Packages\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class UsageCounter extends Model
{
    use BelongsToTenant;

    protected $table = 'usage_counters';

    protected $fillable = [
        'store_id',
        'metric_key',
        'period_start',
        'period_end',
        'count',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'count' => 'integer',
        ];
    }
}
