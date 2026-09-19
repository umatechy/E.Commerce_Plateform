<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A configurable role within a store (Module 02 §5 "Configurable Role
 * System"). Store-scoped — a role defined by one store is never visible
 * or assignable within another store, enforced by BelongsToTenant.
 *
 * Platform-level roles (Super Admin hierarchy, Module 02 §5 "Platform
 * Role Hierarchy") are a SEPARATE concept, stored on
 * users.platform_role, not in this table — kept structurally distinct so
 * a store-scoped role can never accidentally grant platform access.
 */
final class Role extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'roles';

    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }
}
