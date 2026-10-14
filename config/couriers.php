<?php

return [
    /*
    | Phase B49: the "Test courier", which moves no parcel and lets the
    | courier flow be used before a real courier's credentials exist. It is
    | never available when APP_ENV is production, whatever this says
    | (App\Domain\Shipping\Couriers\CourierRegistry).
    */
    'simulator' => (bool) env('COURIERS_SIMULATOR', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),

    /*
    | Tracking reads: a parcel is asked about every `poll_minutes`, and less
    | often after failures (doubling, at most `max_backoff_minutes`).
    */
    'poll_minutes' => 15,
    'max_backoff_minutes' => 360,
];
