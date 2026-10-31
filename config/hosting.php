<?php

// Shared hosting (docs/deployment/hostinger.md): no process may run forever,
// so one cron entry starts the scheduler every minute and the scheduler also
// empties the queue. Off where a queue worker runs (the Docker deployment).
return [
    'queue_from_scheduler' => (bool) env('QUEUE_FROM_SCHEDULER', false),
];
