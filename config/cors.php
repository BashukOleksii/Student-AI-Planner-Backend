<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', ''))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    // Vue must be able to read retry/backoff information on credentialed responses.
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
    'max_age' => 0,
    'supports_credentials' => true,
];
