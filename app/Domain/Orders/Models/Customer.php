<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Minimal foundation only — see
 * docs/development/b5-inspection-findings.md "Scope Decision". Full
 * Customer Management (addresses, storefront-facing auth, order
 * history self-service) belongs to Module 10, not built here.
 */
final class Customer extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'customers';

    protected $fillable = ['store_id', 'user_id', 'name', 'email', 'phone'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
