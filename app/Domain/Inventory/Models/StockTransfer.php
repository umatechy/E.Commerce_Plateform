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
 * Phase P4a (Module 08 §71 "transfers"): stock sent from one location to
 * another. in_transit → received, or → cancelled (back to the source).
 *
 * @property int $id
 * @property string $public_id
 * @property string $number
 * @property int $from_warehouse_id
 * @property int $to_warehouse_id
 * @property string $status
 * @property ?string $note
 * @property ?int $created_by
 * @property ?int $closed_by
 * @property ?\Illuminate\Support\Carbon $closed_at
 */
final class StockTransfer extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'stock_transfers';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    /** @return HasMany<StockTransferLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
