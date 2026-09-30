<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token model, with one change: resolving a token's owner
 * bypasses the BelongsToTenant global scope.
 *
 * Token lookup is necessarily platform-level — it is how the request's
 * principal (and therefore its tenant) gets identified in the first
 * place, so no TenantContext can exist yet. Without this, loading a
 * Customer tokenable (a BelongsToTenant model) threw
 * TenantContextMissingException inside Sanctum's guard and every
 * customer-token request returned 500 (found on the first real run).
 *
 * This does not widen access: the token row itself is the credential,
 * Sanctum still checks the tokenable against the guard's provider model
 * (Customer vs User), and ResolveTenantContext then resolves the tenant
 * from the verified Customer's own store_id.
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable')->withoutGlobalScope('tenant');
    }
}
