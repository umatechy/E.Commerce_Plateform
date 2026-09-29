<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MarketingSegment extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'marketing_segments';

    protected $fillable = ['store_id', 'name', 'rules'];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'marketing_segment_id');
    }
}
