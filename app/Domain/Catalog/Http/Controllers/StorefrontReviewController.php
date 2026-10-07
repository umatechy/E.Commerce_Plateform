<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductReview;
use App\Domain\Catalog\Services\ReviewService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Storefront\Services\StorefrontCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner decision 15 — Module 05 §27: a product's approved reviews for
 * shoppers, and a customer's own review. The product must be one the
 * storefront shows; the store's package must include reviews (Business,
 * Premium) and the store must have them on — otherwise 404, as if the
 * feature did not exist.
 */
final class StorefrontReviewController
{
    private const PER_PAGE = 10;

    public function index(Request $request, string $slug, ReviewService $reviews): JsonResponse
    {
        $product = $this->product($slug, $reviews);
        $customer = $request->user() instanceof Customer ? $request->user() : null;
        $page = ProductReview::query()->where('product_id', $product->id)->where('status', ProductReview::APPROVED)
            ->latest('id')->paginate(self::PER_PAGE);
        $eligibility = $reviews->eligibility($customer, $product);

        return response()->json([
            'data' => collect($page->items())->map(fn (ProductReview $r) => $this->present($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'summary' => $reviews->summary($product),
            // Whether the shopper may write one, and their own (also before it is approved).
            'can_review' => $eligibility['allowed'],
            'reason' => $eligibility['reason'],
            'own' => $eligibility['review'] !== null ? [...$this->present($eligibility['review']), 'status' => $eligibility['review']->status] : null,
        ]);
    }

    public function store(Request $request, string $slug, ReviewService $reviews): JsonResponse
    {
        $product = $this->product($slug, $reviews);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        /** @var Customer $customer */
        $customer = $request->user();
        $review = $reviews->submit($customer, $product, (int) $data['rating'], $data['title'] ?? null, $data['body']);

        return response()->json(['data' => [...$this->present($review), 'status' => $review->status]], $review->wasRecentlyCreated ? 201 : 200);
    }

    private function product(string $slug, ReviewService $reviews): Product
    {
        abort_unless($reviews->enabled(), 404);

        return app(StorefrontCatalog::class)->product($slug) ?? abort(404);
    }

    /** @return array<string, mixed> what shoppers may see — never the customer's email or full name */
    private function present(ProductReview $r): array
    {
        return [
            'id' => $r->id, 'rating' => $r->rating, 'title' => $r->title, 'body' => $r->body, 'author' => $r->author_name,
            'verified_purchase' => $r->verified_purchase, 'created_at' => $r->created_at->toIso8601String(),
            'reply' => $r->status === ProductReview::APPROVED ? $r->reply : null,
        ];
    }
}
