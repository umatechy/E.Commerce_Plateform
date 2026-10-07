<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductReview;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Storefront\Services\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owner decision 15 (2026-10-07) — Module 05 §14, §27, Module 10 §31,
 * Module 15 §32: product ratings and reviews, and units sold, on Business
 * and Premium storefronts.
 *
 * Who may review: a signed-in customer who bought the product (an order of
 * theirs with it that was not cancelled, failed or a draft) and may order
 * (a blocked customer may not — Module 10 §31). One review per customer and
 * product; changing it sends it back to moderation. Every review is
 * therefore a verified purchase — nothing on the storefront is made up.
 *
 * The store approves or rejects (unless it chose to approve automatically)
 * and may reply. The product's rating counts approved reviews only and is
 * kept in integers (total ÷ count), recomputed on every change.
 */
final class ReviewService
{
    public const FEATURE = 'reviews.product';

    public const UNITS_FEATURE = 'products.units_sold';

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly ConfigService $config,
        private readonly AuditLogger $audit,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /** The store's package includes reviews and the store has them on. */
    public function enabled(): bool
    {
        return $this->entitlements->hasFeature(self::FEATURE) && (bool) $this->config->get('reviews.enabled');
    }

    /**
     * Units sold, when the package includes it, the store shows it and the
     * number reaches the store's minimum; otherwise null (not shown).
     */
    public function unitsSoldShown(int $productId): ?int
    {
        if (! $this->entitlements->hasFeature(self::UNITS_FEATURE) || ! (bool) $this->config->get('storefront.show_units_sold')) {
            return null;
        }
        $units = app(StorefrontCatalog::class)->unitsSoldOf($productId);

        return $units >= max(1, (int) $this->config->get('storefront.units_sold_minimum')) ? $units : null;
    }

    /**
     * Whether this customer may review this product now, and why not.
     *
     * @return array{allowed: bool, reason: ?string, order_id: ?int, review: ?ProductReview}
     */
    public function eligibility(?Customer $customer, Product $product): array
    {
        $no = fn (string $reason) => ['allowed' => false, 'reason' => $reason, 'order_id' => null, 'review' => null];
        if (! $this->enabled()) {
            return $no('reviews_off');
        }
        if ($customer === null) {
            return $no('sign_in');
        }
        if (! $customer->standing()->mayOrder()) {
            return $no('not_allowed');
        }
        $orderId = Order::query()->where('customer_id', $customer->id)
            ->whereNotIn('status', ['draft', 'cancelled', 'failed'])
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->orderByDesc('id')->value('id');
        if ($orderId === null) {
            return $no('not_bought');
        }

        return ['allowed' => true, 'reason' => null, 'order_id' => (int) $orderId, 'review' => ProductReview::query()->where('product_id', $product->id)->where('customer_id', $customer->id)->first()];
    }

    /** A new review, or the customer's changed one (back to moderation unless the store approves automatically). */
    public function submit(Customer $customer, Product $product, int $rating, ?string $title, string $body): ProductReview
    {
        $check = $this->eligibility($customer, $product);
        if (! $check['allowed']) {
            throw ValidationException::withMessages(['review' => match ($check['reason']) {
                'not_bought' => 'Only customers who bought this product can review it.',
                'not_allowed' => 'This account cannot write reviews. Please contact the store.',
                default => 'Reviews are not open for this product.',
            }]);
        }
        $title = $title !== null && trim($title) !== '' ? trim($title) : null;

        return DB::transaction(function () use ($customer, $product, $rating, $title, $body, $check) {
            $review = $check['review'] ?? new ProductReview(['product_id' => $product->id, 'customer_id' => $customer->id]);
            $review->fill([
                'order_id' => $check['order_id'], 'rating' => $rating, 'title' => $title, 'body' => trim($body),
                'author_name' => $this->authorName($customer->name),
                'status' => (bool) $this->config->get('reviews.auto_approve') ? ProductReview::APPROVED : ProductReview::PENDING,
                'verified_purchase' => true,
            ])->save();
            $this->refresh($product);
            $this->outbox->recordEvent('review.submitted', ['review_id' => $review->id, 'product_id' => $product->id, 'status' => $review->status], "review:{$review->id}:submitted:".Str::ulid());

            return $review;
        });
    }

    public function moderate(ProductReview $review, string $status, User $actor): ProductReview
    {
        if (! in_array($status, [ProductReview::APPROVED, ProductReview::REJECTED], true)) {
            throw ValidationException::withMessages(['status' => 'Approve or reject the review.']);
        }

        return DB::transaction(function () use ($review, $status, $actor) {
            $before = $review->status;
            $review->forceFill(['status' => $status, 'moderated_by_user_id' => $actor->id, 'moderated_at' => now()])->save();
            $this->refresh($review->product()->firstOrFail());
            $this->audit->record('review.moderated', ['from' => $before, 'to' => $status, 'product_id' => $review->product_id], $review, null, $actor);

            return $review;
        });
    }

    public function reply(ProductReview $review, ?string $reply, User $actor): ProductReview
    {
        $reply = $reply !== null && trim($reply) !== '' ? trim($reply) : null;
        $review->forceFill(['reply' => $reply, 'replied_at' => $reply === null ? null : now()])->save();
        $review->product()->firstOrFail()->touch(); // the product page shows the reply
        $this->audit->record('review.replied', ['product_id' => $review->product_id, 'removed' => $reply === null], $review, null, $actor);

        return $review;
    }

    public function delete(ProductReview $review, User $actor): void
    {
        DB::transaction(function () use ($review, $actor) {
            $product = $review->product()->firstOrFail();
            $this->audit->record('review.deleted', ['product_id' => $review->product_id, 'rating' => $review->rating, 'status' => $review->status], $review, null, $actor);
            $review->delete();
            $this->refresh($product);
        });
    }

    /** Recounts the product's approved reviews (saving the product also refreshes the storefront cache). */
    public function refresh(Product $product): void
    {
        $row = ProductReview::query()->where('product_id', $product->id)->where('status', ProductReview::APPROVED)
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(rating), 0) AS t')->first();
        $product->forceFill(['review_count' => (int) ($row->c ?? 0), 'rating_total' => (int) ($row->t ?? 0)])->save();
    }

    /**
     * The rating as shown: the average to one decimal (integers: total × 10 ÷
     * count, half up) and the count; null without approved reviews.
     *
     * @return array{average: float, count: int}|null
     */
    public static function rating(int $count, int $total): ?array
    {
        if ($count <= 0) {
            return null;
        }

        return ['average' => intdiv(2 * $total * 10 + $count, 2 * $count) / 10, 'count' => $count];
    }

    /** @return array{average: float, count: int, distribution: array<int, int>}|null approved reviews of a product */
    public function summary(Product $product): ?array
    {
        $rating = self::rating((int) $product->review_count, (int) $product->rating_total);
        if ($rating === null) {
            return null;
        }
        $counts = ProductReview::query()->where('product_id', $product->id)->where('status', ProductReview::APPROVED)
            ->groupBy('rating')->selectRaw('rating, COUNT(*) AS c')->pluck('c', 'rating');

        return [...$rating, 'distribution' => array_map(fn (int $stars) => (int) ($counts[$stars] ?? 0), [5 => 5, 4 => 4, 3 => 3, 2 => 2, 1 => 1])];
    }

    /** "Ayesha K." — the first name and the first letter of the last (Module 05 §27 "customer name", without the full name). */
    private function authorName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1).'.' : '';

        return mb_substr(trim("{$first} {$last}"), 0, 80) ?: 'Customer';
    }
}
