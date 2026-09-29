<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PromotionTarget extends Model
{
    use BelongsToTenant;

    protected $table = 'promotion_targets';

    protected $fillable = ['store_id', 'promotion_id', 'target_type', 'target_id'];

    /** @return BelongsTo<Promotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
