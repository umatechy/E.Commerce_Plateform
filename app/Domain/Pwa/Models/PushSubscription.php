<?php

declare(strict_types=1);

namespace App\Domain\Pwa\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B55 (Module 25 §51–54): one browser's permission to receive a
 * store's push notifications. The endpoint and keys are encrypted at rest
 * and never sent back to anyone; the row is found by the endpoint's hash.
 * Store-scoped (BelongsToTenant) and purged with the store.
 */
final class PushSubscription extends Model
{
    use BelongsToTenant;

    protected $table = 'pwa_push_subscriptions';

    protected $guarded = ['id'];

    protected $hidden = ['endpoint', 'public_key', 'auth_secret'];

    protected function casts(): array
    {
        return ['endpoint' => 'encrypted', 'public_key' => 'encrypted', 'auth_secret' => 'encrypted', 'revoked_at' => 'datetime'];
    }
}
