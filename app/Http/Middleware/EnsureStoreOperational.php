<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use App\Domain\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase B51 (Module 03 §33, §35–36; Module 05 §5) — what the Store Admin
 * may still do when the store is not running normally:
 *
 * - suspended or closed: everything can be read and the store's data
 *   exported, but nothing changed — except paying Umar Techy, talking to
 *   Umar Techy's support, the account's own sign-in and security, and the
 *   closure page itself (reopen);
 * - archived, deleting or deleted: only those same exceptions; the store's
 *   data is not shown any more.
 *
 * Applied to the whole signed-in staff API. Super Admin routes are not
 * affected: Umar Techy acts on such stores on purpose.
 */
final class EnsureStoreOperational
{
    /** Paths (under /api/v1/) that stay open for a store that is not operational. */
    /** Phase B54: help is platform content, open to every signed-in store user. */
    private const ALWAYS_OPEN = ['auth/', 'store/switch', 'store-lifecycle', 'billing', 'platform-support/', 'subscription', 'help/'];

    /** Also open for reading while suspended or closed: the data export. */
    private const READ_ONLY_STATES = [StoreStatus::Suspended, StoreStatus::Cancelled];

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $path = (string) preg_replace('#^api/v1/#', '', $request->path());
        if (str_starts_with($path, 'super-admin') || ! $this->context->hasStore()) {
            return $next($request);
        }
        $store = Store::query()->withTrashed()->find($this->context->storeId());
        if ($store === null || $store->isOperational()) {
            return $next($request);
        }
        foreach (self::ALWAYS_OPEN as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }
        $reading = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($reading && in_array($store->status, self::READ_ONLY_STATES, true)) {
            return $next($request);
        }

        [$code, $message] = match ($store->status) {
            StoreStatus::Suspended => ['store_suspended', 'This store is suspended by Umar Techy. Nothing can be changed until it is reactivated.'],
            StoreStatus::Cancelled => ['store_closed', 'This store is closed. Reopen it on the Close store page to make changes.'],
            default => ['store_archived', 'This store is archived. Contact the Umar Techy team.'],
        };

        return response()->json(['message' => $message, 'code' => $code], 423);
    }
}
