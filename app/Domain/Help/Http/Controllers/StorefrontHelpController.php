<?php

declare(strict_types=1);

namespace App\Domain\Help\Http\Controllers;

use App\Domain\Help\Services\HelpLibrary;
use App\Domain\Help\Support\HelpCatalog;
use App\Domain\Settings\Services\Locales;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase B54 (Module 34 §28, §43) — "Was this helpful?" on a store's help
 * center. Shoppers can only rate customer articles; the feedback carries
 * the store (removed with its data), never who sent it.
 */
final class StorefrontHelpController
{
    public function feedback(Request $request, HelpLibrary $library, string $key): JsonResponse
    {
        $data = $request->validate([
            'helpful' => ['required', 'boolean'],
            'reason' => ['nullable', Rule::in(array_keys(HelpCatalog::FEEDBACK_REASONS))],
            'comment' => ['nullable', 'string', 'max:500'],
            'locale' => ['nullable', Rule::in(array_keys(Locales::SUPPORTED))],
        ]);
        /** @var Store $store */
        $store = $request->attributes->get('storefront.store');
        $stored = $library->feedback($key, ['customer'], $data['locale'] ?? 'en', (bool) $data['helpful'], $data['reason'] ?? null, $data['comment'] ?? null, 'storefront', $store->id);
        abort_unless($stored, 404);

        return response()->json(['message' => 'Thank you for your feedback.'], 201);
    }
}
