<?php

return [
    /*
    | Phase B50: the "Test messenger" SMS and WhatsApp providers, which send
    | nothing and let the flow be used before real accounts exist. Never
    | available when APP_ENV is production, whatever this says.
    */
    'simulator' => (bool) env('MESSAGING_SIMULATOR', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),

    /*
    | The Graph API version used for WhatsApp (Meta publishes a version per
    | release; v25.0 is the one in its documentation at the time of B50).
    */
    'meta_graph_version' => env('META_GRAPH_VERSION', 'v25.0'),
];
