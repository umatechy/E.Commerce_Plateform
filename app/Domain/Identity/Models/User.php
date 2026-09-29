<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Models\Store;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Platform user identity (Module 02). A single User row may hold
 * memberships in multiple Stores (store_user pivot) plus, separately, a
 * platform_role for Super Admin staff (Module 30) — the two are distinct
 * and never conflated (a store membership never implies platform access).
 *
 * NOTE: User is intentionally NOT tenant-scoped via BelongsToTenant — a
 * user identity is platform-level; *access* to a given store is governed
 * by the store_user pivot + ADR-001 tenant resolution, not by the User
 * row itself belonging to one store.
 */
final class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasPublicId, Notifiable, SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'platform_role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_user')
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    /**
     * Server-resolved "current active store" for this authenticated
     * session — read by ResolveTenantContext middleware. Backed by
     * App\Domain\Tenancy\Support\StoreSwitcher, which verifies the
     * session-persisted selection (or the user's single active
     * membership) against real membership data on every read — never
     * trusted from unauthenticated client input (ADR-001 §8 Layer 1).
     *
     * Fixed in Phase B1 (see docs/development/b1-inspection-findings.md
     * item B): the original single-store-only lookup is now delegated to
     * StoreSwitcher, which correctly supports users with memberships in
     * more than one store via an explicit, server-verified switch.
     */
    public function activeStoreId(): ?int
    {
        return app(\App\Domain\Tenancy\Support\StoreSwitcher::class)->currentStoreId($this);
    }

    public function isPlatformStaff(): bool
    {
        return $this->platform_role !== null;
    }
}
