<?php

declare(strict_types=1);

namespace App\Domain\Domains\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * "Dumb" like every other core-state model — DomainService is the
 * only writer of `status`/`is_primary`. `BelongsToTenant` guards every
 * STAFF-facing query; `DomainResolverService` (the anonymous-request
 * Host lookup, which by definition runs BEFORE any tenant context
 * exists) explicitly bypasses it via `withoutTenantScope()` — the one
 * legitimate, documented exception.
 */
final class Domain extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'domains';

    protected $fillable = [
        'store_id', 'hostname', 'normalized_hostname', 'domain_type', 'status',
        'is_primary', 'verification_token', 'verification_token_expires_at',
        'verified_at', 'ssl_status',
    ];

    protected $hidden = ['verification_token'];

    protected function casts(): array
    {
        return [
            'domain_type' => DomainType::class,
            'status' => DomainStatus::class,
            'ssl_status' => SslStatus::class,
            'is_primary' => 'boolean',
            'verification_token_expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
