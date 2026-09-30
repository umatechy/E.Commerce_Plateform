<?php

declare(strict_types=1);

/*
 * Module 05 — Storefront (Phase B24).
 */
return [
    // Public catalog responses are cached per store and invalidated by a
    // per-store version bump whenever catalog data changes, so this TTL is
    // only an upper bound on memory use, never on staleness.
    'cache_ttl_seconds' => (int) env('STOREFRONT_CACHE_TTL', 600),

    'per_page' => 24,
    'max_per_page' => 48,

    // Below this many units a variant shows as "low stock". Exact stock
    // counts are never published.
    'low_stock_threshold' => (int) env('STOREFRONT_LOW_STOCK_THRESHOLD', 5),

    'images' => [
        'disk' => env('STOREFRONT_IMAGE_DISK', 'public'),
        'max_per_product' => 12,
        'max_kilobytes' => 5120,
        'min_dimension' => 100,
        'max_dimension' => 6000,
    ],
];
