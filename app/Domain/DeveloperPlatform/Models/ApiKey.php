<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** `key_hash` is $hidden — never returned via any Resource. ApiKeyService is the only writer of `status`/`last_used_at`. */
final class ApiKey extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'api_keys';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected $fillable = [
        'developer_application_id', 'store_id', 'key_prefix', 'key_hash',
        'scopes', 'status', 'last_used_at', 'expires_at', 'revoked_at',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'status' => ApiKeyStatus::class,
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DeveloperApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(DeveloperApplication::class, 'developer_application_id');
    }

    public function hasScope(ApiScope $scope): bool
    {
        return in_array($scope->value, $this->scopes, true);
    }

    public function isUsable(): bool
    {
        return $this->status === ApiKeyStatus::Active && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
