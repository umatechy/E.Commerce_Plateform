<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase B39 — Module 05 §16, Module 06 §34: a merchandising group of
 * products. Manual (products picked and ordered by hand) or rule-based
 * (CollectionRules), optionally scheduled. A product may be in many.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property ?string $description
 * @property string $type
 * @property ?array<int, array<string, mixed>> $rules
 * @property string $match
 * @property string $sort
 * @property string $status
 * @property bool $is_visible
 * @property ?\Illuminate\Support\Carbon $starts_at
 * @property ?\Illuminate\Support\Carbon $ends_at
 * @property int $sort_order
 */
final class Collection extends Model
{
    use BelongsToTenant, HasPublicId, SoftDeletes;

    public const TYPES = ['manual', 'rule'];
    public const SORTS = ['manual', 'newest', 'price_asc', 'price_desc', 'name', 'best_selling'];

    protected $attributes = ['type' => 'manual', 'match' => 'all', 'sort' => 'manual', 'status' => 'active', 'is_visible' => true, 'sort_order' => 0];

    protected $fillable = ['name', 'slug', 'description', 'type', 'rules', 'match', 'sort', 'status', 'is_visible', 'starts_at', 'ends_at', 'sort_order'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'is_visible' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'sort_order' => 'integer'];
    }

    /** @return BelongsToMany<Product, $this> the products picked by hand, in their order */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('position')->orderByPivot('position');
    }

    /** Active, visible and inside its schedule (Module 06 §34 "scheduled"). */
    public function isLive(): bool
    {
        return $this->status === 'active' && $this->is_visible
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /**
     * @param Builder<Collection> $query
     * @return Builder<Collection>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('is_visible', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
