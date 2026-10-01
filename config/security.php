<?php

// Phase B29 (gap G2) — the security baseline: multi-factor
// authentication, step-up authentication and request IDs
// (Module 32 §8, §9.10, §65; Module 30 §4–6; SRS AUTH-006/007/012,
// SA-004, API-011).
//
// The numbers below are proposed defaults. The specifications require
// the controls but set no durations.
return [
    'mfa' => [
        // The name an authenticator app shows next to the account.
        'issuer' => env('MFA_ISSUER', env('APP_NAME', 'Umar Techy')),

        // Module 32 §65.3: platform staff cannot use the Super Admin
        // surface without MFA. Turn off only for local development.
        'required_for_platform_staff' => (bool) env('MFA_REQUIRED_FOR_PLATFORM_STAFF', true),

        // Owner decision 2026-10-01: a Store Owner cannot use the Store
        // Admin surface with a password alone. Store staff stay optional.
        'required_for_store_owners' => (bool) env('MFA_REQUIRED_FOR_STORE_OWNERS', true),

        'recovery_codes' => 10,

        // How long a password-verified sign-in may wait for its code.
        'challenge_ttl_minutes' => 10,

        // Wrong codes allowed per account before a pause.
        'max_attempts' => 5,
        'lockout_minutes' => 15,
    ],

    'step_up' => [
        // Module 30 §6: sensitive actions need a recent re-authentication.
        'enabled' => (bool) env('STEP_UP_ENABLED', true),
        'ttl_minutes' => (int) env('STEP_UP_TTL_MINUTES', 15),
    ],

    'request_id' => [
        'header' => 'X-Request-Id',
    ],
];
