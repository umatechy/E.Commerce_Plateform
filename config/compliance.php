<?php

declare(strict_types=1);

/**
 * Module 32 — Security, Audit & Compliance (Phase B22).
 */
return [
    'audit' => [
        // Rows older than this are pruned by `audit:prune` (daily). The
        // chain stays verifiable: the last pruned hash becomes its anchor.
        // 365 days matches the previous file-based audit channel.
        'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

        // Also write every entry to Log::channel('audit') (storage/logs/
        // audit-*.log), so existing log shipping keeps working.
        'mirror_to_log' => (bool) env('AUDIT_MIRROR_TO_LOG', true),

        // Context keys whose values are never stored, matched as
        // case-insensitive substrings of the key.
        'redact_keys' => ['password', 'secret', 'token', 'authorization', 'cookie', 'cvv', 'card_number', 'key_hash', 'signature'],

        // Longer string values are truncated before storage.
        'max_value_length' => 1000,
    ],

    'security_headers' => [
        // HSTS is only ever sent over HTTPS; disable while a domain is
        // still being moved to TLS.
        'hsts' => (bool) env('SECURITY_HSTS_ENABLED', true),
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
    ],
];
