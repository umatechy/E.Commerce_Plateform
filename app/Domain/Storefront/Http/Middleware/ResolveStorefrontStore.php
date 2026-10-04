<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Http\Middleware;

use App\Domain\Domains\Services\DomainResolverService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Storefront\Models\StorefrontAvailability;
use App\Domain\Storefront\Services\StorefrontGate;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 05 — which store's storefront is this, and is it open?
 *
 * The storefront is the store being VISITED, which is not necessarily
 * the store of whoever is signed in: a merchant browsing another shop
 * must see that shop. So this middleware re-resolves TenantContext for
 * storefront routes only, from:
 *  - `path`: the {storeSlug} route segment (/shop/{storeSlug}/...);
 *  - `host`: a verified, active custom domain (Module 19), else 404;
 *  - `api`:  the verified domain of the Host header, then X-Store-Slug,
 *            then a signed-in customer's own store. A customer token is
 *            only valid on its own store (a store slug is public
 *            information; a customer token is a credential).
 * A slug selects public data only, as in ResolveTenantContext's B6
 * fallback; carts stay protected by their own guest token.
 *
 * Then StorefrontGate decides whether shoppers may see the store. Staff
 * of the store may preview it before launch (web pages only).
 */
final class ResolveStorefrontStore
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StorefrontGate $gate,
        private readonly DomainResolverService $domains,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'api'): Response
    {
        $store = match ($mode) {
            'path' => Store::query()->where('slug', (string) $request->route('storeSlug'))->first(),
            'host' => $this->domains->resolveHost($request->getHost()),
            default => $this->fromApiRequest($request),
        };

        if ($store === null) {
            return $this->refuse($request, $mode, 404, 'store_not_found', 'This store does not exist.');
        }

        $customer = $request->user();
        if ($customer instanceof Customer && $customer->store_id !== $store->id) {
            return $this->refuse($request, $mode, 403, 'store_mismatch', 'This account belongs to a different store.');
        }

        $this->context->resolveToStore($store->id);
        $request->attributes->set('storefront.store', $store);
        // Consumed here: controller actions receive only their own slug
        // (Laravel passes route parameters positionally).
        $request->route()?->forgetParameter('storeSlug');
        $request->route()?->forgetParameter('storefrontHost');
        $request->attributes->set('storefront.base_path', $mode === 'path' ? '/shop/'.$store->slug : '');
        // Phase B38: the language of this visit (after the store, whose settings decide what is offered).
        app(\App\Domain\Storefront\Services\StorefrontLocale::class)->resolve($request, (string) $request->attributes->get('storefront.base_path'));

        $availability = $this->gate->availability($store);
        $preview = $availability === StorefrontAvailability::NotLaunched && $mode !== 'api' && $this->isStaffOf($request, $store);
        $request->attributes->set('storefront.preview', $preview);

        if ($availability !== StorefrontAvailability::Open && ! $preview) {
            return $this->refuse($request, $mode, 503, "storefront_{$availability->value}", $availability->message(), $store);
        }

        return $next($request);
    }

    private function fromApiRequest(Request $request): ?Store
    {
        if (($store = $this->domains->resolveHost($request->getHost())) !== null) {
            return $store;
        }

        if (($slug = $request->header('X-Store-Slug')) !== null) {
            return Store::query()->where('slug', $slug)->first();
        }

        $customer = $request->user();

        return $customer instanceof Customer ? Store::query()->find($customer->store_id) : null;
    }

    private function isStaffOf(Request $request, Store $store): bool
    {
        $user = $request->user();

        return $user instanceof \App\Domain\Identity\Models\User
            && $user->stores()->where('stores.id', $store->id)->wherePivot('status', 'active')->exists();
    }

    private function refuse(Request $request, string $mode, int $status, string $code, string $message, ?Store $store = null): Response
    {
        if ($mode === 'api') {
            return response()->json(['message' => $message, 'code' => $code], $status)
                ->withHeaders($status === 503 ? ['Retry-After' => '3600'] : []);
        }

        return Inertia::render('Storefront/Unavailable', [
            'status' => $status,
            'code' => $code,
            'message' => $message,
            'store' => $store !== null ? ['name' => $store->name] : null,
        ])->toResponse($request)->setStatusCode($status);
    }
}
