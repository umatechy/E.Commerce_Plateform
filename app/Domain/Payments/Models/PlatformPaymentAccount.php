<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B48 — Umar Techy's own merchant account with a provider, used only
 * to collect subscription invoices from stores (Module 29; Module 12 §92
 * keeps that money apart from stores' sales). A platform catalogue row,
 * like Package: not tenant-scoped, changed only by platform staff.
 */
final class PlatformPaymentAccount extends Model
{
    use HasPublicId, MerchantAccount;

    protected $table = 'platform_payment_accounts';

    protected $fillable = [
        'provider', 'environment', 'is_enabled', 'credentials', 'credential_hints',
        'verified_at', 'last_success_at', 'last_failure_at', 'last_failure_category', 'updated_by',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return $this->merchantAccountCasts();
    }
}
