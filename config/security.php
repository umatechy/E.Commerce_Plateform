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

        // Owner decision 2026-10-21 (replaces the one of 2026-10-01): two-step
        // sign-in is the Store Owner's own choice, turned on from Security, so a
        // new owner reaches the dashboard straight after registering. SRS
        // AUTH-006 and Module 32 §8.1 ask for MFA to be supported, not required.
        // Sensitive actions still ask for the password again (step-up below).
        // Set MFA_REQUIRED_FOR_STORE_OWNERS=true to require it of every owner again.
        'required_for_store_owners' => (bool) env('MFA_REQUIRED_FOR_STORE_OWNERS', false),

        'recovery_codes' => 10,

        // How long a password-verified sign-in may wait for its code.
        'challenge_ttl_minutes' => 10,

        // Wrong codes allowed per account before a pause.
        'max_attempts' => 5,
        'lockout_minutes' => 15,
    ],

    // Deployment: the reverse proxies in front of the app (comma-separated IPs
    // or CIDR ranges, e.g. the Docker network of the production Compose
    // project). Empty: no proxy is trusted. Only X-Forwarded-For (the
    // client's IP, for rate limits and audit) and X-Forwarded-Proto (HTTPS)
    // are read from them — never X-Forwarded-Host: the Host header decides
    // which store a request belongs to, so it must come from the request.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    'step_up' => [
        // Module 30 §6: sensitive actions need a recent re-authentication.
        'enabled' => (bool) env('STEP_UP_ENABLED', true),
        'ttl_minutes' => (int) env('STEP_UP_TTL_MINUTES', 15),
    ],

    // Phase B52 (Module 30 §5, Module 32 §65.4): platform staff sessions end sooner.
    // Design defaults, not owner decisions; change them here or by environment.
    'sessions' => [
        'privileged_idle_minutes' => (int) env('PRIVILEGED_SESSION_IDLE_MINUTES', 30),
        'privileged_absolute_hours' => (int) env('PRIVILEGED_SESSION_ABSOLUTE_HOURS', 12),
    ],
    // Phase B52 (Module 32 §58–59): security monitoring.
    'monitoring' => [
        'mass_export_rows' => (int) env('SECURITY_MASS_EXPORT_ROWS', 5000),
        'events_retention_days' => (int) env('SECURITY_EVENTS_RETENTION_DAYS', 180),
    ],
    'request_id' => [
        'header' => 'X-Request-Id',
    ],
];
