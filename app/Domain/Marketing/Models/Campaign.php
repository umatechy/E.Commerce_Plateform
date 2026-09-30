<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Domain\Promotions\Models\Promotion;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 15 §5-9. "Dumb" like every other core-state model —
 * CampaignService is the only writer of `status`.
 */
final class Campaign extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'campaigns';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'store_id', 'name', 'objective', 'channel', 'status', 'audience_type',
        'marketing_segment_id', 'promotion_id', 'subject', 'body',
        'scheduled_at', 'activated_at', 'completed_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'objective' => CampaignObjective::class,
            'status' => CampaignStatus::class,
            'audience_type' => CampaignAudienceType::class,
            'scheduled_at' => 'datetime',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MarketingSegment, $this> */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(MarketingSegment::class, 'marketing_segment_id');
    }

    /** @return BelongsTo<Promotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }
}
