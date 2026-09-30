<?php

// Module 16 §10/§15-16 "Canonical URL Engine / Domain Integration /
// Host Header Security". No Module 19 (Domain Management) exists yet
// — see docs/development/b13-inspection-findings.md "Architectural
// Decision — Canonical URL Base". This single configured base URL is
// used for EVERY canonical/sitemap/Open-Graph URL this platform
// generates; it is NEVER derived from the request's raw Host header.
return [
    // Phase B24: the storefront lives at {APP_URL}/shop/{slug} unless a
    // store has a verified custom domain (which SeoResolver prefers).
    'storefront_base_url' => env('STOREFRONT_BASE_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/shop'),

    // Module 16 §19 "Sitemap Performance" — a documented threshold
    // above which sitemap output is chunked into an index + numbered
    // sitemap files rather than one unbounded XML document.
    'sitemap_chunk_size' => 5000,
];
