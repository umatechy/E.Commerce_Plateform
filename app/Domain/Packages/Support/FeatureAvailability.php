<?php

declare(strict_types=1);

namespace App\Domain\Packages\Support;

/**
 * Which package features the platform does not have yet (owner decision
 * 2026-10-26: a store is never sold what is not built). A package may still
 * carry such a feature — it is the plan for that tier — but every screen
 * shows it as "Coming soon", apart from what the package really gives, and
 * the public pricing never names it.
 *
 * When a feature is built, it leaves this list in the same change.
 *   - loyalty.points, advanced_analytics.enabled: phases P2 and P3
 *   - inventory.advanced, marketing.advanced, integrations.advanced: phase P4
 *   - ai.recommendations, ai.chatbot: later (owner decision: AI afterwards)
 */
final class FeatureAvailability
{
    public const COMING_SOON = [
        'loyalty.points',
        'advanced_analytics.enabled',
        'inventory.advanced',
        'marketing.advanced',
        'integrations.advanced',
        'ai.recommendations',
        'ai.chatbot',
    ];

    public static function isComingSoon(string $key): bool
    {
        return in_array($key, self::COMING_SOON, true);
    }
}
