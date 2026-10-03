<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
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
    use Authenticatable, BelongsToTenant, HasApiTokens, HasFactory, HasPublicId, SoftDeletes;

    protected $table = 'customers';

    protected $fillable = ['store_id', 'user_id', 'name', 'email', 'phone', 'password', 'marketing_email_opt_in'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'marketing_email_opt_in' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'erased_at' => 'datetime', // Module 32 — personal data erased on request
            'status' => \App\Domain\Customers\Models\CustomerStatus::class, // Module 10 §29 (Phase B32)
            'source' => \App\Domain\Customers\Models\CustomerSource::class,
            'status_changed_at' => 'datetime',
            'merged_at' => 'datetime', // Module 10 §56 (customer merge)
        ];
    }

    /**
     * Schema-ready link to a platform User (Module 10 §4's future
     * "one human, multiple stores" identity federation) — NOT the
     * authentication mechanism. A Customer never authenticates by
     * being a User; see class docblock.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<\App\Domain\Cart\Models\Cart, $this> */
    public function carts(): HasMany
    {
        return $this->hasMany(\App\Domain\Cart\Models\Cart::class);
    }

    /** @return HasMany<\App\Domain\Cart\Models\WishlistItem, $this> */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(\App\Domain\Cart\Models\WishlistItem::class);
    }

    /** @return BelongsTo<\App\Domain\Customers\Models\CustomerGroup, $this> Module 10 §25 (Phase B32) */
    public function group(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Customers\Models\CustomerGroup::class, 'customer_group_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Domain\Customers\Models\CustomerTag, $this> Module 10 §24 (Phase B32) */
    public function tags(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Domain\Customers\Models\CustomerTag::class, 'customer_tag_assignments', 'customer_id', 'customer_tag_id')->orderBy('customer_tags.name');
    }

    /** @return HasMany<\App\Domain\Customers\Models\CustomerNote, $this> Module 10 §32 (Phase B32); staff only */
    public function notes(): HasMany
    {
        return $this->hasMany(\App\Domain\Customers\Models\CustomerNote::class);
    }

    /** @return BelongsTo<self, $this> Module 10 §56: the record this one was merged into */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_customer_id');
    }

    /** Module 10 §29–31 (Phase B32). A row from before B32, or one not yet refreshed, counts as active. */
    public function standing(): \App\Domain\Customers\Models\CustomerStatus
    {
        return $this->status ?? \App\Domain\Customers\Models\CustomerStatus::Active;
    }

    public function isRegistered(): bool
    {
        return $this->password !== null;
    }
}
