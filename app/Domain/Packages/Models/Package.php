<?php

declare(strict_types=1);

namespace App\Domain\Packages\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Basic / Business / Premium (Module 04). PLATFORM-level catalog — a
 * Package definition is not owned by any one store; stores subscribe to
 * one via Subscription. Never tenant-scoped, never duplicated per store.
 */
final class Package extends Model
{
    use HasFactory;

    protected $table = 'packages';

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<PackageEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PackageEntitlement::class);
    }

    /**
     * Phase B31 (gap G6): the API names a package by its `code` and never
     * returns its numeric key, so the Super Admin page had nothing to put
     * in the update URL. A code now resolves too; the numeric key still
     * does, for callers written before.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $value = (string) $value;

        return self::query()->where($field ?? (ctype_digit($value) ? 'id' : 'code'), $value)->first();
    }
}
