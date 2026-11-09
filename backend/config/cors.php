<?php

/*
 * CORS: which websites may call this API from a browser.
 *
 * The React app runs on a different origin (localhost:5173 locally, *.vercel.app
 * in production), so the browser asks this API for permission first. We allow
 * only our own frontend(s), listed comma-separated in FRONTEND_URL.
 *
 * supports_credentials is false because auth uses bearer tokens in the
 * Authorization header, not cookies (see docs/DECISIONS.md, D7).
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        fn (string $origin) => rtrim(trim($origin), '/'),
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Let the browser cache the preflight (OPTIONS) response for an hour.
    'max_age' => 3600,

    'supports_credentials' => false,

];
