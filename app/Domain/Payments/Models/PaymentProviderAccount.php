<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B48 — Module 12 §31–34: a store's own merchant account with one
 * provider (one per provider per store). Tenant-scoped: an account can
 * never be used for another store's payments (Module 12 Final Rule #22).
 *
 * @property int $store_id
 * @property ?int $min_amount_minor
 * @property ?int $max_amount_minor
 */
final class PaymentProviderAccount extends Model
{
    use BelongsToTenant, HasPublicId, MerchantAccount;

    protected $table = 'payment_provider_accounts';

    protected $fillable = [
        'store_id', 'provider', 'environment', 'is_enabled', 'credentials', 'credential_hints',
        'min_amount_minor', 'max_amount_minor', 'verified_at', 'last_success_at', 'last_failure_at',
        'last_failure_category', 'updated_by',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [...$this->merchantAccountCasts(), 'min_amount_minor' => 'integer', 'max_amount_minor' => 'integer'];
    }
}
