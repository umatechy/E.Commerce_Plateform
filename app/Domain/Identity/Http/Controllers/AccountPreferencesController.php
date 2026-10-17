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
 */
final class AccountPreferencesController
{
    public const THEMES = ['indigo', 'emerald', 'slate'];
    public const LANGUAGES = ['en', 'ur'];

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['sometimes', 'string', 'in:'.implode(',', self::THEMES)],
            'language' => ['sometimes', 'string', 'in:'.implode(',', self::LANGUAGES)],
        ]);
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['admin_preferences' => array_merge(self::of($user), $data)])->save();

        return response()->json(['data' => self::of($user)]);
    }

    /** @return array{theme?: string, language?: string} the stored preferences, valid values only */
    public static function of(User $user): array
    {
        $stored = is_array($user->admin_preferences) ? $user->admin_preferences : [];

        return array_filter([
            'theme' => in_array($stored['theme'] ?? null, self::THEMES, true) ? $stored['theme'] : null,
            'language' => in_array($stored['language'] ?? null, self::LANGUAGES, true) ? $stored['language'] : null,
        ]);
    }
}
