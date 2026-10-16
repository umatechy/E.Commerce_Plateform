<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Models;

use App\Domain\Tenancy\Models\Store;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B58 (Module 28 §17, §29): a store a reseller brought — how, when,
 * and the terms of their tier at that moment. The store's owner is the
 * client, never the reseller. At most one attribution of a store is open
 * (ended_at null); Umar Techy can end it and attribute the store anew.
 *
 * @property int $id
 * @property string $public_id
 * @property int $reseller_id
 * @property int $store_id
 * @property string $source
 * @property ?string $provision_key
 * @property array{tier: string, client_discount_bp: int, initial_commission_bp: int, recurring_commission_bp: int, recurring_months: ?int, hold_days: int} $terms
 * @property ?int $attributed_by
 * @property ?string $note
 * @property ?\Illuminate\Support\Carbon $ended_at
 * @property ?string $ended_reason
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Reseller $reseller
 * @property-read Store $store
 */
final class ResellerStore extends Model
{
    use HasPublicId;

    protected $table = 'reseller_stores';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['terms' => 'array', 'ended_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Reseller, $this> */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class)->withoutGlobalScopes();
    }
}
