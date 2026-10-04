<?php

declare(strict_types=1);

namespace App\Domain\StoreCredit\Models;

use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 09 §52: a customer's store credit in one currency. The balance
 * is written only by StoreCreditService, together with the ledger entry
 * that explains it.
 *
 * @property int $id
 * @property int $store_id
 * @property int $customer_id
 * @property string $currency
 * @property int $balance_minor
 */
final class StoreCreditAccount extends Model
{
    use BelongsToTenant;

    protected $table = 'store_credit_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['balance_minor' => 'integer'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<StoreCreditEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(StoreCreditEntry::class, 'account_id');
    }
}
