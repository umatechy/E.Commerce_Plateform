<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase P4a (Module 08 §71 "purchase receiving"): stock received from a
 * supplier into one location. Quantities only — no costing.
 *
 * @property int $id
 * @property string $public_id
 * @property string $number
 * @property int $warehouse_id
 * @property string $supplier
 * @property ?string $reference
 * @property ?string $note
 * @property ?int $received_by
 */
final class StockReceipt extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'stock_receipts';

    protected $guarded = ['id', 'public_id'];

    /** @return HasMany<StockReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockReceiptLine::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
