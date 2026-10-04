<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Cart\Models\CartStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatus;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ProductVisibility;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Orders\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 11 §2-25. This is the ONLY code path that creates/mutates a
 * Cart or CartItem — no controller writes cart state directly.
 *
 * CRITICAL, per Module 11 Final Rule #19 and this milestone's Step 6:
 * this class NEVER calls InventoryService::reserve(). Adding an item to
 * a normal cart does not reserve stock — only Checkout does (via
 * OrderService, unchanged from Phase B5). Cart-level stock checks here
 * are informational/soft (read-only availability queries), never a
 * reservation.
 */
final class CartService
{
    /**
     * Resolves (creating if necessary) the ONE active cart for the
     * given principal. Exactly one of $customer/$guestToken is
     * expected — mirrors the ownership model in Module 11 §5.
     */
    /**
     * The currency a new cart starts in: the store's own (owner decision
     * 2026-10-03: PKR unless the store chose another). Replaces the B6
     * placeholder 'USD', which left a PKR store's carts without shipping
     * rates (rates are matched by currency).
     */
    public function storeCurrency(): string
    {
        return (string) app(\App\Domain\Settings\Services\ConfigService::class)->get('store.default_currency');
    }

    public function activeCartFor(?Customer $customer, ?string $guestToken, string $currency): Cart
    {
        $query = Cart::query()->where('status', CartStatus::Active);

        $cart = $customer !== null
            ? $query->where('customer_id', $customer->id)->first()
            : $query->where('guest_token', $guestToken)->first();

        if ($cart !== null) {
            return $cart;
        }

        return Cart::query()->create([
            'customer_id' => $customer?->id,
            'guest_token' => $customer === null ? $guestToken : null,
            'status' => CartStatus::Active,
            'currency' => $currency,
        ]);
    }

    /**
     * Module 11 §62 "Customer Ownership" — the single, shared cart
     * resolution logic used by every controller that needs "the
     * current requester's cart" (CartController, CheckoutController).
     * Centralized here rather than duplicated per-controller.
     *
     * SECURITY: a client-presented guest token is only ever used to
     * LOOK UP an existing cart — never as the value for a NEWLY
     * CREATED cart (Module 11 §63 requires server-generated,
     * non-sequential tokens; trusting a client-invented value here
     * would let anyone choose/collide with another guest's identifier).
     *
     * @return array{0: Cart, 1: ?string} [$cart, $newlyIssuedGuestToken]
     */
    public function resolveForRequest(\Illuminate\Http\Request $request, string $guestTokenHeader, string $defaultCurrency): array
    {
        $customer = $request->user() instanceof Customer ? $request->user() : null;

        if ($customer !== null) {
            return [$this->activeCartFor($customer, null, $defaultCurrency), null];
        }

        $presentedToken = $request->header($guestTokenHeader);
        $existing = $presentedToken !== null ? $this->findActiveGuestCart($presentedToken) : null;

        if ($existing !== null) {
            return [$existing, null]; // client already possesses the correct, valid token
        }

        $freshToken = $this->newGuestToken();

        return [$this->activeCartFor(null, $freshToken, $defaultCurrency), $freshToken];
    }

    public function newGuestToken(): string
    {
        // High entropy, non-sequential (Module 11 §63) — 32 random
        // bytes, hex-encoded, never derived from any guessable seed
        // (timestamp, sequence, customer data).
        return bin2hex(random_bytes(32));
    }

    /**
     * Looks up an EXISTING active guest cart by a client-presented
     * token — never creates one (see CartController::resolveCart()'s
     * docblock for why a client-supplied token must never become a new
     * record's identifier).
     */
    public function findActiveGuestCart(string $guestToken): ?Cart
    {
        return Cart::query()->where('status', CartStatus::Active)->where('guest_token', $guestToken)->first();
    }

    /**
     * Module 11 §11-12 "Product Validation / Quantity Validation".
     *
     * @throws ValidationException
     */
    public function addItem(Cart $cart, ?int $productId, ?int $productVariantId, int $quantity): CartItem
    {
        $this->assertCartActionable($cart);

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be at least 1.']);
        }

        [$product, $variant, $priceMinor] = $this->resolvePurchasableItem($productId, $productVariantId);

        return DB::transaction(function () use ($cart, $product, $variant, $quantity, $priceMinor) {
            // Phase B24 fix: this looked up `product_id IS NULL` for a
            // variant, never found the existing line, and the insert then
            // hit uniq_cart_items_cart_id_product_id_product_variant_id —
            // adding the same variant twice answered 500.
            $existing = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->when(
                    $variant !== null,
                    fn ($q) => $q->where('product_variant_id', $variant->id),
                    fn ($q) => $q->whereNull('product_variant_id')
                )
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'quantity' => $existing->quantity + $quantity,
                    'price_at_add_minor' => $priceMinor,
                ]);

                return $existing;
            }

            return CartItem::query()->create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => $quantity,
                'price_at_add_minor' => $priceMinor,
            ]);
        });
    }

    /**
     * @throws ValidationException
     */
    public function updateItemQuantity(Cart $cart, CartItem $item, int $quantity): CartItem
    {
        $this->assertCartActionable($cart);
        abort_unless($item->cart_id === $cart->id, 404);

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be at least 1 — remove the item instead of setting quantity to 0.']);
        }

        $item->update(['quantity' => $quantity]);

        return $item->fresh();
    }

    public function removeItem(Cart $cart, CartItem $item): void
    {
        $this->assertCartActionable($cart);
        abort_unless($item->cart_id === $cart->id, 404);

        $item->delete();
    }

    /**
     * Module 11 §13-14 "Cart Price / Price Changes" + §19 "Stock
     * Availability" (soft check — see class docblock). Recomputes
     * EVERY line's live price/availability on every call — this is the
     * single centralized place cart totals are calculated, consumed by
     * both GET /cart (display) and CheckoutService (final validation).
     *
     * @return array{items: list<array<string, mixed>>, subtotal_minor: int, currency: ?string, has_issues: bool, coupon_code: ?string, promotion: array<string, mixed>}
     */
    public function totals(Cart $cart): array
    {
        $items = [];
        $subtotal = 0;
        $hasIssues = false;

        foreach ($cart->items()->with(['product.images', 'variant'])->get() as $item) {
            $product = $item->product;
            $variant = $item->variant;
            $issue = null;
            $currentPriceMinor = null;
            $available = null;

            if ($product === null || ($item->product_variant_id !== null && $variant === null)) {
                $issue = 'unavailable'; // product/variant hard-deleted or cross-tenant-orphaned
            } elseif ($product->status !== ProductStatus::Active || $product->visibility !== ProductVisibility::Public) {
                $issue = 'unavailable';
            } else {
                $currentPriceMinor = $variant !== null ? $variant->effectivePriceMinor() : $product->effectivePriceMinor();
                $available = $this->availableQuantity($product, $variant);

                if ($currentPriceMinor === null) {
                    $issue = 'unavailable';
                } elseif ($currentPriceMinor !== $item->price_at_add_minor) {
                    $issue = 'price_changed';
                } elseif ($available !== null && $available < $item->quantity) {
                    $issue = 'insufficient_stock';
                }
            }

            if ($issue !== null) {
                $hasIssues = true;
            }

            $lineTotal = $currentPriceMinor !== null ? $currentPriceMinor * $item->quantity : null;
            $subtotal += $lineTotal ?? 0;

            $items[] = [
                'cart_item_id' => $item->id,
                'product_id' => $product?->public_id,
                // Phase B24: what a storefront cart needs to show the line.
                // Phase B38: in the shopper's language when the store has a translation.
                'product_name' => \App\Domain\Storefront\Support\StorefrontText::productName($product),
                'product_slug' => $product?->slug,
                'variant_id' => $variant?->public_id,
                'variant_options' => $variant?->option_values,
                'image_url' => $product !== null ? $this->lineImageUrl($product, $variant) : null,
                'quantity' => $item->quantity,
                'price_at_add_minor' => $item->price_at_add_minor,
                'current_price_minor' => $currentPriceMinor,
                'line_total_minor' => $lineTotal,
                'available' => $available,
                'issue' => $issue,
            ];
        }

        return [
            'items' => $items,
            'subtotal_minor' => $subtotal,
            'currency' => $cart->currency,
            'has_issues' => $hasIssues,
            'coupon_code' => $cart->coupon_code,
            'promotion' => $this->promotionPreview($cart, $items, $subtotal),
        ];
    }

    /**
     * Module 11 §13-14 / Module 14 Step 10 "Cart Integration" — a
     * PREVIEW only, using the exact same
     * PromotionEligibilityEngine::evaluate() call CheckoutService uses
     * authoritatively (never a duplicated/approximated calculation).
     * An invalid/ineligible stored coupon is shown as "no discount"
     * here rather than thrown — Checkout is where an invalid coupon
     * actually blocks the request (this is just informational display).
     */
    private function promotionPreview(Cart $cart, array $items, int $subtotalMinor): array
    {
        $cartItemContexts = $cart->items()->with('product.categories')->get()->map(fn ($item) => [
            'product_id' => $item->product_id,
            'category_ids' => $item->product?->categories->pluck('id')->all() ?? [],
            'brand_id' => $item->product?->brand_id,
            'collection_ids' => $item->product_id !== null ? app(\App\Domain\Catalog\Services\CollectionService::class)->promotionCollectionIdsFor($item->product_id) : [],
            'line_total_minor' => $items[array_search($item->id, array_column($items, 'cart_item_id'), true)]['line_total_minor'] ?? 0,
        ])->all();

        try {
            $result = app(\App\Domain\Promotions\Services\PromotionEligibilityEngine::class)->evaluate(
                $cartItemContexts, $subtotalMinor, $cart->currency ?? $this->storeCurrency(),
                $cart->customer, $cart->coupon_code,
            );
        } catch (\App\Domain\Promotions\Exceptions\CouponNotEligibleException) {
            return ['applied' => false, 'discount_amount_minor' => 0, 'free_shipping' => false, 'error' => $cart->coupon_code !== null ? 'coupon_not_eligible' : null];
        }

        return [
            'applied' => $result->hasPromotion(),
            'discount_amount_minor' => $result->freeShipping ? 0 : $result->discountAmountMinor,
            'free_shipping' => $result->freeShipping,
            'error' => null,
        ];
    }

    /**
     * Module 14 §26-27 "Coupon Validation / Coupon Removal". Does a
     * LIGHTWEIGHT existence/eligibility check (via the same engine) so
     * an obviously-invalid code is rejected immediately rather than
     * only surfacing as a checkout failure — but Checkout ALWAYS
     * re-validates from scratch regardless (this is a UX convenience,
     * never the authoritative check).
     *
     * @throws \App\Domain\Promotions\Exceptions\CouponNotEligibleException
     */
    public function applyCoupon(Cart $cart, string $code): Cart
    {
        $this->assertCartActionable($cart);

        $totals = $this->totals($cart);
        app(\App\Domain\Promotions\Services\PromotionEligibilityEngine::class)->evaluate(
            [], $totals['subtotal_minor'], $totals['currency'] ?? $this->storeCurrency(), $cart->customer, $code,
        );

        $cart->update(['coupon_code' => $code]);

        return $cart->fresh();
    }

    public function removeCoupon(Cart $cart): Cart
    {
        $this->assertCartActionable($cart);
        $cart->update(['coupon_code' => null]);

        return $cart->fresh();
    }

    /**
     * Module 11 §22 "Cart Merge". Deterministic: quantities SUM per
     * matching product/variant; the guest cart is marked MERGED
     * (Module 11 §8), never deleted (historical record preserved,
     * consistent with every other append/soft-delete convention in
     * this codebase).
     */
    public function mergeGuestCartIntoCustomer(string $guestToken, Customer $customer): ?Cart
    {
        $guestCart = Cart::query()->where('guest_token', $guestToken)->where('status', CartStatus::Active)->first();

        if ($guestCart === null) {
            return null;
        }

        return DB::transaction(function () use ($guestCart, $customer) {
            $customerCart = $this->activeCartFor($customer, null, $guestCart->currency ?? $this->storeCurrency());

            foreach ($guestCart->items as $guestItem) {
                $existing = CartItem::query()
                    ->where('cart_id', $customerCart->id)
                    ->where('product_id', $guestItem->product_id)
                    ->where('product_variant_id', $guestItem->product_variant_id)
                    ->first();

                if ($existing !== null) {
                    $existing->update(['quantity' => $existing->quantity + $guestItem->quantity]);
                } else {
                    CartItem::query()->create([
                        'cart_id' => $customerCart->id,
                        'product_id' => $guestItem->product_id,
                        'product_variant_id' => $guestItem->product_variant_id,
                        'quantity' => $guestItem->quantity,
                        'price_at_add_minor' => $guestItem->price_at_add_minor,
                    ]);
                }
            }

            $guestCart->update(['status' => CartStatus::Merged]);

            return $customerCart->fresh(['items']);
        });
    }

    private function assertCartActionable(Cart $cart): void
    {
        abort_if(! $cart->status->isActionable(), 422, 'This cart is no longer active.');
    }

    /**
     * @return array{0: Product, 1: ?ProductVariant, 2: int}
     * @throws ValidationException
     */
    private function resolvePurchasableItem(?int $productId, ?int $productVariantId): array
    {
        $variant = null;
        $product = null;

        if ($productVariantId !== null) {
            $variant = ProductVariant::query()->with('product')->find($productVariantId);

            if ($variant === null) {
                throw ValidationException::withMessages(['product_variant_id' => 'This variant does not exist in this store.']);
            }

            $product = $variant->product;
        } elseif ($productId !== null) {
            $product = Product::query()->find($productId);

            if ($product === null) {
                throw ValidationException::withMessages(['product_id' => 'This product does not exist in this store.']);
            }
        } else {
            throw ValidationException::withMessages(['product_id' => 'A product or variant must be specified.']);
        }

        // Module 11 §11: status ACTIVE + visibility PUBLIC required to enter a cart.
        if ($product->status !== ProductStatus::Active || $product->visibility !== ProductVisibility::Public) {
            throw ValidationException::withMessages(['product_id' => 'This product is not currently available for purchase.']);
        }

        $priceMinor = $variant !== null ? $variant->effectivePriceMinor() : $product->effectivePriceMinor();

        if ($priceMinor === null) {
            throw ValidationException::withMessages(['product_id' => 'This product does not have a price configured.']);
        }

        return [$product, $variant, $priceMinor];
    }

    /**
     * Soft/informational availability check ONLY — never reserves
     * stock (see class docblock). Returns null if no Inventory record
     * exists (treated as "unknown", not zero — a cart display should
     * not falsely claim "out of stock" for an item Checkout might
     * still legitimately reject or accept based on the same rules
     * OrderService already enforces).
     */
    /** The variant's own image when it has one, else the product's first. */
    private function lineImageUrl(Product $product, ?ProductVariant $variant): ?string
    {
        $images = $product->images;
        $image = ($variant !== null ? $images->firstWhere('product_variant_id', $variant->id) : null) ?? $images->first();

        return $image?->url();
    }

    private function availableQuantity(Product $product, ?ProductVariant $variant): ?int
    {
        $warehouse = Warehouse::query()->where('is_default', true)->first();

        if ($warehouse === null) {
            return null;
        }

        $inventory = Inventory::query()
            ->where('warehouse_id', $warehouse->id)
            ->when(
                $variant !== null,
                fn ($q) => $q->where('product_variant_id', $variant->id),
                fn ($q) => $q->where('product_id', $product->id)->whereNull('product_variant_id')
            )
            ->first();

        return $inventory?->available();
    }
}
