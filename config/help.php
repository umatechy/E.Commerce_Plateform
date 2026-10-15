<?php

declare(strict_types=1);

/*
 * Phase B54 (Module 34 §8, §31) — the help center.
 *
 * product_version: the version of the platform this code is. Articles say
 * which version they describe, and release notes cannot be newer than it.
 * It changes with the code, so it is not an environment setting.
 */
return [
    'product_version' => '1.1',

    // Bundled help content, reviewed in the repository (Module 34 §58).
    'content_path' => resource_path('help'),

    // Search analytics and handled feedback are kept this many days (Module 34 §29).
    'search_retention_days' => 180,
    'feedback_retention_days' => 365,
];
