<?php

/*
 * Cross-origin requests (D-101). The API uses Bearer tokens, not cookies, so no credentials are shared. When the
 * frontend is served from the same origin as the API (the deployment guide's layout), none of this is used. When it
 * is served from another origin, list it in CORS_ALLOWED_ORIGINS (comma-separated, e.g. https://hr.example.local);
 * the default "*" is for development only.
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // The frontend reads the file names of exports and downloads, and how long to wait after a 429.
    'exposed_headers' => ['Content-Disposition', 'Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
