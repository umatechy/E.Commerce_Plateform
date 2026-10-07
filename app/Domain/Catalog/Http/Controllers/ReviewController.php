<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Models\ProductReview;
use App\Domain\Catalog\Services\ReviewService;
use App\Domain\Packages\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Owner decision 15 — Module 05 §27 "store administrators must control
 * moderation": the store's reviews, newest first, to approve, reject, reply
 * to or delete. Business and Premium (reviews.product).
 */
final class ReviewController
{
    public function index(Request $request, EntitlementService $entitlements): JsonResponse
    {
        $this->authorize($request, $entitlements);
        $filters = $request->validate(['status' => ['nullable', Rule::in(ProductReview::STATUSES)], 'search' => ['nullable', 'string', 'max:100']]);
        $page = ProductReview::query()->with('product:id,public_id,name,slug')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('body', 'like', "%{$term}%")->orWhere('title', 'like', "%{$term}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"))))
            ->latest('id')->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (ProductReview $r) => [
                'id' => $r->id, 'rating' => $r->rating, 'title' => $r->title, 'body' => $r->body, 'author' => $r->author_name,
                'status' => $r->status, 'verified_purchase' => $r->verified_purchase, 'reply' => $r->reply,
                'created_at' => $r->created_at->toIso8601String(), 'moderated_at' => $r->moderated_at?->toIso8601String(),
                'product' => $r->product === null ? null : ['id' => $r->product->public_id, 'name' => $r->product->name, 'slug' => $r->product->slug],
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'counts' => ProductReview::query()->groupBy('status')->selectRaw('status, COUNT(*) AS c')->pluck('c', 'status'),
        ]);
    }

    public function status(Request $request, ProductReview $review, ReviewService $reviews, EntitlementService $entitlements): JsonResponse
    {
        $this->authorize($request, $entitlements, $review);
        $data = $request->validate(['status' => ['required', Rule::in([ProductReview::APPROVED, ProductReview::REJECTED])]]);

        return response()->json(['data' => ['id' => $review->id, 'status' => $reviews->moderate($review, $data['status'], $request->user())->status]]);
    }

    public function reply(Request $request, ProductReview $review, ReviewService $reviews, EntitlementService $entitlements): JsonResponse
    {
        $this->authorize($request, $entitlements, $review);
        $data = $request->validate(['reply' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => ['id' => $review->id, 'reply' => $reviews->reply($review, $data['reply'] ?? null, $request->user())->reply]]);
    }

    public function destroy(Request $request, ProductReview $review, ReviewService $reviews, EntitlementService $entitlements): Response
    {
        $this->authorize($request, $entitlements, $review);
        $reviews->delete($review, $request->user());

        return response()->noContent();
    }

    private function authorize(Request $request, EntitlementService $entitlements, ?ProductReview $review = null): void
    {
        Gate::forUser($request->user())->authorize('manage', $review ?? ProductReview::class);
        $entitlements->assertFeatureEntitled(ReviewService::FEATURE);
    }
}
