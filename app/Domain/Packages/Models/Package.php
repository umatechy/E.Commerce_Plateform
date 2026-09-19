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

    public function entitlements(): HasMany
    {
        return $this->hasMany(PackageEntitlement::class);
    }
}
