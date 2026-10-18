<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UI-20: PUT /api/v1/auth/preferences — the signed-in user's own admin
 * preferences (theme colour, admin language). Only the known keys and
 * values are kept; anything else is refused.
 *
 * UI-21: also the user's saved list views (named filters) for the orders,
 * products and customers lists — at most ten per list.
 */
final class AccountPreferencesController
{
    public const THEMES = ['indigo', 'emerald', 'slate'];
    public const LANGUAGES = ['en', 'ur'];
    public const MODES = ['light', 'dark', 'system'];
    public const VIEW_LISTS = ['orders', 'products', 'customers'];

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['sometimes', 'string', 'in:'.implode(',', self::THEMES)],
            'language' => ['sometimes', 'string', 'in:'.implode(',', self::LANGUAGES)],
            'mode' => ['sometimes', 'string', 'in:'.implode(',', self::MODES)], // UI-22
            'saved_views' => ['sometimes', 'array:'.implode(',', self::VIEW_LISTS)],
            'saved_views.*' => ['array', 'max:10'],
            'saved_views.*.*.name' => ['required', 'string', 'max:40'],
            'saved_views.*.*.query' => ['present', 'nullable', 'string', 'max:500'],
        ]);
        if (isset($data['saved_views'])) {
            // Only name and query of each view, in the order given.
            $data['saved_views'] = array_map(fn (array $views) => array_values(array_map(fn (array $v) => ['name' => $v['name'], 'query' => (string) ($v['query'] ?? '')], $views)), $data['saved_views']);
            $data['saved_views'] = array_merge(self::of($request->user())['saved_views'] ?? [], $data['saved_views']);
        }
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['admin_preferences' => array_merge(self::of($user), $data)])->save();

        return response()->json(['data' => self::of($user)]);
    }

    /** @return array{theme?: string, language?: string, mode?: string, saved_views?: array<string, list<array{name: string, query: string}>>} the stored preferences, valid values only */
    public static function of(User $user): array
    {
        $stored = is_array($user->admin_preferences) ? $user->admin_preferences : [];

        return array_filter([
            'theme' => in_array($stored['theme'] ?? null, self::THEMES, true) ? $stored['theme'] : null,
            'language' => in_array($stored['language'] ?? null, self::LANGUAGES, true) ? $stored['language'] : null,
            'mode' => in_array($stored['mode'] ?? null, self::MODES, true) ? $stored['mode'] : null,
            'saved_views' => is_array($stored['saved_views'] ?? null) ? array_intersect_key($stored['saved_views'], array_flip(self::VIEW_LISTS)) : null,
        ]);
    }
}
