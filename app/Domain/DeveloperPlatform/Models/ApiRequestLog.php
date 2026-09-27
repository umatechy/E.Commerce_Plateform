<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Append-only, metadata-only (Module 31 §21, Non-Negotiable — see migration). */
final class ApiRequestLog extends Model
{
    use BelongsToTenant;

    protected $table = 'api_request_logs';

    public $timestamps = false;

    protected $fillable = ['store_id', 'api_key_id', 'method', 'endpoint', 'status_code', 'duration_ms'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
