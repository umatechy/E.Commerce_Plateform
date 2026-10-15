<?php

declare(strict_types=1);

/*
 * Phase B55 (gap G16; Module 25) — the stores' installable apps.
 *
 * VAPID keys identify Umar Techy to browsers' push services. They are
 * generated once for the platform (`php artisan pwa:vapid-keys` prints a
 * pair) and set in the server environment. Never commit them. Without
 * them, stores cannot ask shoppers for push notifications.
 */
return [
    'vapid' => [
        'public_key' => env('PWA_VAPID_PUBLIC_KEY'),
        'private_key' => env('PWA_VAPID_PRIVATE_KEY'),
        // A contact for push services: mailto: or https: (set with the keys).
        'subject' => env('PWA_VAPID_SUBJECT'),
    ],

    // Images the service worker keeps for offline browsing, per store.
    'image_cache_entries' => 60,
    // Pages kept for offline browsing, per store.
    'page_cache_entries' => 30,

    // App counters a store collects (Module 25 §68); anything else is refused.
    'events' => ['installed', 'launched', 'offline_view', 'push_on', 'push_off'],
];
