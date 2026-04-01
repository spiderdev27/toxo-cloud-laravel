<?php

return [
    /*
    |--------------------------------------------------------------------------
    | TOXO Cloud API key
    |--------------------------------------------------------------------------
    |
    | Resolved in this order:
    | 1) this config value
    | 2) GEMINI_API_KEY
    | 3) GOOGLE_API_KEY
    | 4) TOXO_CLOUD_API_KEY
    |
    */

    'api_key' => env('TOXO_CLOUD_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Default HTTP timeout (seconds)
    |--------------------------------------------------------------------------
    */

    'timeout' => env('TOXO_CLOUD_TIMEOUT', 120),
];

