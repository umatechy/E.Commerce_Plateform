<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

use App\Domain\Payments\Providers\ProviderCredentials;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B49 — Module 13 §44–46, §88: a store's own account with a courier.
 * Tenant-scoped (one store's account is never used for another's parcels,
 * §45); credentials encrypted at rest and never serialised; they leave the
 * model only as a ProviderCredentials object for one call.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property string $provider
 * @property string $environment
 * @property bool $is_enabled
 * @property array<string, string> $credentials
 * @property ?array<string, string> $credential_hints
 * @property ?\Illuminate\Support\Carbon $last_success_at
 * @property ?\Illuminate\Support\Carbon $last_failure_at
 * @property ?string $last_failure_message
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
final class CourierAccount extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'courier_accounts';

    protected $fillable = [
        'store_id', 'provider', 'environment', 'is_enabled', 'credentials', 'credential_hints',
        'last_success_at', 'last_failure_at', 'last_failure_message', 'updated_by',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'credential_hints' => 'array',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function providerCredentials(): ProviderCredentials
    {
        return new ProviderCredentials(array_map('strval', (array) $this->credentials), $this->environment);
    }
}
