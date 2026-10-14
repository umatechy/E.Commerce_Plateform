<?php

return [
    /*
    | Phase B48: the "Test wallet" payment provider, which moves no money and
    | lets the online payment flow be used before real provider credentials
    | exist. It is never available when APP_ENV is production, whatever this
    | says (App\Domain\Payments\Providers\ProviderRegistry).
    */
    'simulator' => (bool) env('PAYMENTS_SIMULATOR', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
];
