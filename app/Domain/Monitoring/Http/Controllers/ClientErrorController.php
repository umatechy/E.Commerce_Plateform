<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Http\Controllers;

use App\Domain\Monitoring\Models\ClientError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * UI-25 — script errors from the admin's browsers.
 *
 * store(): the admin reports one (signed-in users only, throttled). The page
 * path loses its query string and its ids, so the same broken page counts as
 * one error and nothing personal is kept.
 *
 * index(): Super Admin's list, most recently seen first.
 */
final class ClientErrorController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'path' => ['required', 'string', 'max:2000'],
        ]);
        $message = Str::limit(trim($data['message']), 497);
        $source = self::topFrame($data['stack'] ?? null);
        $path = self::cleanPath($data['path']);
        $fingerprint = hash('sha256', $message.'|'.$source.'|'.$path);
        $now = now();
        $user = $request->user();

        DB::transaction(function () use ($request, $user, $fingerprint, $message, $source, $path, $now) {
            $existing = ClientError::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
            if ($existing !== null) {
                $existing->forceFill(['occurrences' => $existing->occurrences + 1, 'last_seen_at' => $now])->save();

                return;
            }
            ClientError::query()->create([
                'fingerprint' => $fingerprint, 'message' => $message, 'source' => $source, 'path' => $path,
                'browser' => Str::limit((string) $request->userAgent(), 197), 'occurrences' => 1,
                'store_id' => $user?->activeStoreId(), 'user_id' => $user?->id,
                'first_seen_at' => $now, 'last_seen_at' => $now,
            ]);
        });

        return response()->json(null, 204);
    }

    public function index(): JsonResponse
    {
        $page = ClientError::query()->orderByDesc('last_seen_at')->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (ClientError $e) => [
                'id' => $e->id, 'message' => $e->message, 'source' => $e->source, 'path' => $e->path, 'browser' => $e->browser,
                'occurrences' => $e->occurrences, 'store_id' => $e->store_id,
                'first_seen_at' => $e->first_seen_at->toIso8601String(), 'last_seen_at' => $e->last_seen_at->toIso8601String(),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** The page path without query string or hash, ids (ULIDs, numbers) replaced by ":id". */
    public static function cleanPath(string $path): string
    {
        $clean = (string) (parse_url($path, PHP_URL_PATH) ?: '/');
        $clean = preg_replace('~/[0-9A-HJKMNP-TV-Z]{26}(?=/|$)~i', '/:id', $clean) ?? $clean;
        $clean = preg_replace('~/\d+(?=/|$)~', '/:id', $clean) ?? $clean;

        return Str::limit($clean, 297);
    }

    /** The first stack line that names a place in our code (file:line), without the origin. */
    public static function topFrame(?string $stack): ?string
    {
        if ($stack === null) {
            return null;
        }
        foreach (preg_split('/\R/', $stack) ?: [] as $line) {
            if (preg_match('~(/build/assets/[^\s)]+|/resources/js/[^\s)]+)~', $line, $m) === 1) {
                return Str::limit($m[1], 297);
            }
        }

        return null;
    }
}
