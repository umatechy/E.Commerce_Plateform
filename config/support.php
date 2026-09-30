<?php

declare(strict_types=1);

/*
 * Module 34 — Support (Phase B26).
 */
return [
    // Service levels, in wall-clock hours from when the ticket was opened.
    'sla' => [
        'first_response_hours' => ['urgent' => 1, 'high' => 4, 'normal' => 24, 'low' => 48],
        'resolution_hours' => ['urgent' => 8, 'high' => 24, 'normal' => 72, 'low' => 120],
    ],

    // A requester may reply to (and so reopen) a resolved ticket for this
    // long; after that it is closed and a new ticket is needed.
    'reopen_days' => (int) env('SUPPORT_REOPEN_DAYS', 7),

    'max_message_length' => 10000,
    'max_open_tickets_per_requester' => 20,
];
