<?php

declare(strict_types=1);

return [
    // Module 04 §14 "Trial System" — explicitly required to be
    // configurable by Umar Techy, not hard-coded. Documented
    // implementation decision (see docs/architecture/b2-packages-entitlements.md
    // "Default trial package"): defaults to 'basic' as the lowest-risk
    // choice absent an explicit spec value; change here, not in code.
    'default_trial_package_code' => env('DEFAULT_TRIAL_PACKAGE_CODE', 'basic'),

    // Module 04 §14: "Initial business concept: 7–14 days" — 14 chosen
    // as the upper (more generous) end of that explicit range.
    'default_trial_days' => env('DEFAULT_TRIAL_DAYS', 14),

    'entitlement_cache_ttl_minutes' => env('ENTITLEMENT_CACHE_TTL_MINUTES', 5),
];
