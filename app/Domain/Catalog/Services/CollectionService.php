<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Collection;
use App\Domain\Catalog\Models\Product;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Storefront\Services\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase B39 — Module 05 §16, Module 06 §34, Module 14 §9: the only writer
 * of collections and their hand-picked products, and the answer to "which
 * live collections is this product in" (for promotions).
 */
final class CollectionService
{
    public function __construct(private readonly CollectionRules $rules, private readonly RecordsOutboxEvents $outbox) {}

    /** @param array<string, mixed> $data validated fields */
    public function save(?Collection $collection, array $data, ?int $actorUserId): Collection
    {
        $type = $data['type'] ?? ($collection === null ? 'manual' : $collection->type);
        $rules = $type === 'rule' ? $this->rules->validate($data['rules'] ?? $collection?->rules) : null;
        // The schedule as it will be: a field left out keeps its saved value.
        $startsAt = array_key_exists('starts_at', $data) ? $data['starts_at'] : $collection?->starts_at?->toIso8601String();
        $endsAt = array_key_exists('ends_at', $data) ? $data['ends_at'] : $collection?->ends_at?->toIso8601String();
        if (is_string($startsAt) && is_string($endsAt) && strtotime($endsAt) <= strtotime($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }
        if ($type === 'rule' && ($data['sort'] ?? $collection?->sort) === 'manual') {
            $data['sort'] = 'newest'; // a rule-based collection has no hand-made order
        }

        return DB::transaction(function () use ($collection, $data, $type, $rules, $actorUserId) {
            $fields = [...$data, 'type' => $type, 'rules' => $rules];
            if ($collection === null) {
                $collection = Collection::query()->create([...$fields, 'slug' => $this->uniqueSlug((string) $data['name'])]);
                $event = 'collection.created';
            } else {
                $collection->update($fields);
                $event = 'collection.updated';
            }
            if ($type === 'rule') {
                $collection->products()->detach(); // its products come from the rules now
            }
            $this->outbox->recordEvent(eventType: $event, payload: ['collection_id' => $collection->id], idempotencyKey: "{$event}:{$collection->id}:".Str::ulid());
            app(AuditLogger::class)->record($event, ['type' => $type, 'name' => $collection->name], $collection, null, $actorUserId ? \App\Domain\Identity\Models\User::query()->find($actorUserId) : null);

            return $collection->fresh();
        });
    }

    /**
     * The hand-picked products of a manual collection, in this order.
     *
     * @param list<string> $productPublicIds
     */
    public function setProducts(Collection $collection, array $productPublicIds): void
    {
        if ($collection->type !== 'manual') {
            throw ValidationException::withMessages(['products' => 'A rule-based collection takes its products from its rules.']);
        }
        $ids = Product::query()->whereIn('public_id', $productPublicIds)->pluck('id', 'public_id');
        if ($ids->count() !== count(array_unique($productPublicIds))) {
            throw ValidationException::withMessages(['products' => 'One of the products is not in this store.']);
        }
        $sync = [];
        foreach (array_values(array_unique($productPublicIds)) as $position => $publicId) {
            $sync[$ids[$publicId]] = ['position' => $position];
        }
        $collection->products()->sync($sync);
        $collection->touch(); // the storefront cache follows the collection
    }

    /**
     * The live collections this product is in, among those a promotion of
     * this store targets — so a cart with no collection promotion costs no
     * collection queries at all.
     *
     * @return list<int>
     */
    public function promotionCollectionIdsFor(int $productId): array
    {
        $targeted = \App\Domain\Promotions\Models\PromotionTarget::query()->where('target_type', 'collection')->distinct()->pluck('target_id');
        if ($targeted->isEmpty()) {
            return [];
        }
        $catalog = app(StorefrontCatalog::class);

        return Collection::query()->live()->whereIn('id', $targeted)->get()
            ->filter(fn (Collection $collection) => $catalog->inCollection($collection, $productId))
            ->pluck('id')->values()->all();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'collection';
        $slug = $base;
        for ($i = 2; Collection::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
