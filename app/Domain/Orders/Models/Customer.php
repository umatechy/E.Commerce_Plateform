<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

/**
 * Module 10 §3/§6. Phase B6 upgrade from B5's read-only foundation:
 * Customer is now its OWN authenticatable identity (Authenticatable
 * contract + HasApiTokens), served by the `customer` Sanctum guard
 * (config/auth.php) — entirely separate from the staff `User` model
 * and its `sanctum` guard, per Module 10 §3's explicit "must remain
 * logically separated" requirement. See
 * docs/development/b6-inspection-findings.md "Critical Architectural
 * Decision" for the full rationale, including why
 * EnsureCustomerPrincipal/EnsureStaffPrincipal middleware is ALSO
 * required on top of the separate-guard configuration.
 *
 * `password` is nullable — a guest-order Customer row (Module 09/10:
 * guest checkout) has no password and can never authenticate; only a
 * Customer that completed registration (Phase B6's
 * CustomerAuthController::register()) has one and can log in.
 */
final class Customer extends Model implements AuthenticatableContract
{
    use Authenticatable, BelongsToTenant, HasApiTokens, HasFactory, SoftDeletes;

    protected $table = 'customers';

    protected $fillable = ['store_id', 'user_id', 'name', 'email', 'phone', 'password', 'marketing_email_opt_in'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'marketing_email_opt_in' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Schema-ready link to a platform User (Module 10 §4's future
     * "one human, multiple stores" identity federation) — NOT the
     * authentication mechanism. A Customer never authenticates by
     * being a User; see class docblock.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(\App\Domain\Cart\Models\Cart::class);
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(\App\Domain\Cart\Models\WishlistItem::class);
    }

    public function isRegistered(): bool
    {
        return $this->password !== null;
    }
}
