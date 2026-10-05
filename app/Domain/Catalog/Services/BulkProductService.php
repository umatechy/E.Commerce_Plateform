<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Collection;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatus;
use App\Domain\Catalog\Policies\CollectionPolicy;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Exceptions\UsageLimitExceededException;
use App\Domain\Packages\Services\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Phase B39 — Module 06 §46–47 "Bulk product operations / safety".
 *
 * One action on up to 500 products of the store at once. For every product
 * the same rules apply as for a single change: the permission (checked per
 * product through the policy), the package's product limit (a product coming
 * back from archived counts again; when the limit is reached the rest are
 * skipped, not half-changed), and prices only as whole minor units. Each
 * product is changed in its own transaction, so one failure never undoes the
 * others; the answer lists how many changed and why the others did not. One
 * audit entry records the action and the ids.
 *
 * Phase B43 added add_badge, remove_badge and set_sort_priority.
 *
 * Actions: publish, unpublish, archive, delete, set_visibility, set_featured,
 * set_category (main category), add_category, add_to_collection,
 * remove_from_collection, add_tags, remove_tags, set_price, adjust_price
 * (percent), set_sale_percent, clear_sale.
 */
final class BulkProductService
{
    public const MAX_PRODUCTS = 500;

    public const ACTIONS = [
        'publish', 'unpublish', 'archive', 'delete', 'set_visibility', 'set_featured', 'set_category', 'add_category',
        'add_to_collection', 'remove_from_collection', 'add_tags', 'remove_tags', 'set_price', 'adjust_price', 'set_sale_percent', 'clear_sale',
        'add_badge', 'remove_badge', 'set_sort_priority',
    ];

    public function __construct(private readonly EntitlementService $entitlements, private readonly ProductTagService $tags) {}

    /**
     * @param list<string> $publicIds
     * @param array<string, mixed> $params
     * @return array{affected: int, skipped: list<array{id: string, name: string, reason: string}>}
     */
    public function run(User $user, string $action, array $publicIds, array $params): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => 'Unknown action.']);
        }
        $publicIds = array_values(array_unique($publicIds));
        if ($publicIds === [] || count($publicIds) > self::MAX_PRODUCTS) {
            throw ValidationException::withMessages(['products' => 'Choose between 1 and '.self::MAX_PRODUCTS.' products.']);
        }
        $context = $this->prepare($action, $params);
        if (in_array($action, ['add_to_collection', 'remove_from_collection'], true) && ! app(CollectionPolicy::class)->manage($user)) {
            abort(403, 'Changing collections needs the collections permission.');
        }

        $products = Product::query()->whereIn('public_id', $publicIds)->get();
        $skipped = [];
        foreach (array_diff($publicIds, $products->pluck('public_id')->all()) as $missing) {
            $skipped[] = ['id' => $missing, 'name' => '', 'reason' => 'Not found in this store.'];
        }

        $affected = 0;
        foreach ($products as $product) {
            if (! Gate::forUser($user)->allows($action === 'delete' ? 'delete' : 'update', $product)) {
                $skipped[] = ['id' => $product->public_id, 'name' => $product->name, 'reason' => 'You may not change this product.'];
                continue;
            }
            try {
                $changed = DB::transaction(fn () => $this->apply($product, $action, $params, $context));
                $affected += $changed ? 1 : 0;
                if (! $changed) {
                    $skipped[] = ['id' => $product->public_id, 'name' => $product->name, 'reason' => 'Nothing to change.'];
                }
            } catch (UsageLimitExceededException $e) {
                $skipped[] = ['id' => $product->public_id, 'name' => $product->name, 'reason' => "Your package's product limit ({$e->limit}) is reached."];
            } catch (ValidationException $e) {
                $skipped[] = ['id' => $product->public_id, 'name' => $product->name, 'reason' => (string) collect($e->errors())->flatten()->first()];
            }
        }

        app(AuditLogger::class)->record('product.bulk_'.$action, ['affected' => $affected, 'skipped' => count($skipped), 'products' => $products->pluck('public_id')->all(), 'params' => $params], null, null, $user);

        return ['affected' => $affected, 'skipped' => $skipped];
    }

    /**
     * Checks the action's parameters once, before any product changes.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function prepare(string $action, array $params): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['params' => $message]);

        return match ($action) {
            'set_visibility' => in_array($params['visibility'] ?? null, ['public', 'catalog_only', 'search_only', 'hidden'], true) ? [] : $fail('Choose a visibility.'),
            'set_featured' => is_bool($params['featured'] ?? null) ? [] : $fail('Featured is on or off.'),
            'add_badge', 'remove_badge' => ['badge' => \App\Domain\Catalog\Models\Badge::query()->find((int) ($params['badge_id'] ?? 0)) ?? $fail('Choose one of your badges.')],
            'set_sort_priority' => is_int($params['sort_priority'] ?? null) && $params['sort_priority'] >= -1000 && $params['sort_priority'] <= 1000 ? [] : $fail('Sort priority is a whole number from -1000 to 1000.'),
            'set_category', 'add_category' => ['category' => Category::query()->find((int) ($params['category_id'] ?? 0)) ?? $fail('Choose one of your categories.')],
            'add_to_collection', 'remove_from_collection' => (function () use ($params, $fail) {
                $collection = Collection::query()->where('public_id', (string) ($params['collection'] ?? ''))->first() ?? $fail('Choose one of your collections.');

                return $collection->type === 'manual' ? ['collection' => $collection] : $fail('Products of a rule-based collection come from its rules.');
            })(),
            'add_tags', 'remove_tags' => is_array($params['tags'] ?? null) && $params['tags'] !== [] ? [] : $fail('Give at least one tag.'),
            'set_price' => is_int($params['price_minor'] ?? null) && $params['price_minor'] >= 0 && $params['price_minor'] <= 100_000_000_000 ? [] : $fail('Give the new price.'),
            'adjust_price' => is_numeric($params['percent'] ?? null) && (float) $params['percent'] >= -90 && (float) $params['percent'] <= 500 && (float) $params['percent'] != 0 ? [] : $fail('Give a change from -90% to +500%.'),
            'set_sale_percent' => is_numeric($params['percent'] ?? null) && (float) $params['percent'] > 0 && (float) $params['percent'] < 100 ? [] : $fail('Give a discount between 1% and 99%.'),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $context
     */
    private function apply(Product $product, string $action, array $params, array $context): bool
    {
        return match ($action) {
            'publish' => $this->status($product, ProductStatus::Active, publish: true),
            'unpublish' => $this->status($product, ProductStatus::Draft),
            'archive' => $this->status($product, ProductStatus::Archived),
            'delete' => $this->delete($product),
            'set_visibility' => $this->fill($product, ['visibility' => $params['visibility']]),
            'set_featured' => $this->fill($product, ['is_featured' => (bool) $params['featured']]),
            'set_sort_priority' => $this->fill($product, ['sort_priority' => (int) $params['sort_priority']]),
            'add_badge' => $this->badge($product, $context['badge'], add: true),
            'remove_badge' => $this->badge($product, $context['badge'], add: false),
            'set_category' => $this->category($product, $context['category'], primary: true),
            'add_category' => $this->category($product, $context['category'], primary: false),
            'add_to_collection' => $this->addToCollection($product, $context['collection']),
            'remove_from_collection' => $this->removeFromCollection($product, $context['collection']),
            'add_tags' => $this->changeTags($product, $params['tags'], add: true),
            'remove_tags' => $this->changeTags($product, $params['tags'], add: false),
            'set_price' => $this->price($product, (int) $params['price_minor']),
            'adjust_price' => $product->price_minor === null ? false : $this->price($product, (int) round($product->price_minor * (1 + (float) $params['percent'] / 100))),
            'set_sale_percent' => $product->price_minor === null ? false : $this->fill($product, ['sale_price_minor' => (int) round($product->price_minor * (1 - (float) $params['percent'] / 100))]),
            'clear_sale' => $this->fill($product, ['sale_price_minor' => null]),
            default => throw new \LogicException("Unhandled bulk action {$action}."), // run() accepts ACTIONS only
        };
    }

    private function status(Product $product, ProductStatus $to, bool $publish = false): bool
    {
        $from = $product->status;
        if ($from === $to) {
            return false;
        }
        if (! $from->countsTowardUsageLimit() && $to->countsTowardUsageLimit()) {
            $this->entitlements->assertWithinLimit('max_products');
        }
        if ($publish && $product->price_minor === null && ! $product->variants()->exists()) {
            throw ValidationException::withMessages(['status' => 'A product needs a price before it is published.']);
        }
        $product->update(['status' => $to, ...($publish && $product->published_at === null ? ['published_at' => now()] : [])]);
        if ($from->countsTowardUsageLimit() && ! $to->countsTowardUsageLimit()) {
            $this->entitlements->releaseUsage('max_products');
        } elseif (! $from->countsTowardUsageLimit() && $to->countsTowardUsageLimit()) {
            $this->entitlements->recordUsage('max_products');
        }

        return true;
    }

    private function delete(Product $product): bool
    {
        $counted = $product->status->countsTowardUsageLimit();
        $product->delete(); // soft delete: orders keep their history (Module 06 §32)
        if ($counted) {
            $this->entitlements->releaseUsage('max_products');
        }

        return true;
    }

    /** @param array<string, mixed> $values */
    private function fill(Product $product, array $values): bool
    {
        $product->fill($values);
        if (! $product->isDirty()) {
            return false;
        }
        if ($product->sale_price_minor !== null && $product->price_minor !== null && $product->sale_price_minor >= $product->price_minor) {
            throw ValidationException::withMessages(['sale_price_minor' => 'The sale price must be lower than the price.']);
        }
        $product->save();

        return true;
    }

    private function price(Product $product, int $price): bool
    {
        // A sale price that would no longer be lower is removed rather than left wrong.
        $sale = $product->sale_price_minor !== null && $product->sale_price_minor >= $price ? null : $product->sale_price_minor;

        return $this->fill($product, ['price_minor' => max(0, $price), 'sale_price_minor' => $sale]);
    }

    private function category(Product $product, Category $category, bool $primary): bool
    {
        $product->categories()->syncWithoutDetaching([$category->id]);
        if ($primary) {
            $product->primary_category_id = $category->id;
        }
        $product->save();
        $product->touch();

        return true;
    }

    private function addToCollection(Product $product, Collection $collection): bool
    {
        if ($collection->products()->whereKey($product->id)->exists()) {
            return false;
        }
        $collection->products()->attach($product->id, ['position' => (int) DB::table('collection_product')->where('collection_id', $collection->id)->max('position') + 1]);
        $collection->touch();

        return true;
    }

    private function removeFromCollection(Product $product, Collection $collection): bool
    {
        if ($collection->products()->detach($product->id) === 0) {
            return false;
        }
        $collection->touch();

        return true;
    }

    private function badge(Product $product, \App\Domain\Catalog\Models\Badge $badge, bool $add): bool
    {
        $changed = $add
            ? $product->badges()->syncWithoutDetaching([$badge->id])['attached'] !== []
            : $product->badges()->detach($badge->id) > 0;
        if ($changed) {
            $product->touch(); // the storefront cache follows the product
        }

        return $changed;
    }

    /** @param list<string> $names */
    private function changeTags(Product $product, array $names, bool $add): bool
    {
        $add ? $this->tags->add($product, $names) : $this->tags->remove($product, $names);

        return true;
    }
}
