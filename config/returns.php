<?php

declare(strict_types=1);

/*
 * Module 09 §45 — returns (Phase B33/B34).
 */
return [
    // Photos a customer or staff attach to a return request ("Images").
    // They show a person's goods and sometimes their home, so they are
    // kept on a PRIVATE disk and are only ever served through the API,
    // to the store's staff and to the person who asked for the return.
    'photos' => [
        'disk' => env('RETURN_PHOTO_DISK', 'local'),
        'max_per_return' => 6,
        'max_kilobytes' => 5120,
        'min_dimension' => 50,
        'max_dimension' => 8000,
    ],

    // Module 09 §9: a guest reaches their order's returns through a link
    // sent to the email of the order. Hours the link works.
    'guest_link_hours' => 48,
];
