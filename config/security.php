<?php

return [
    'headers' => [
        'enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),

        'values' => [
            'Content-Security-Policy' => "base-uri 'self'; frame-ancestors 'self'; object-src 'none'",
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=()',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ],
    ],

    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS_ENABLED', env('APP_ENV', 'production') === 'production'),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', false),
        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],
];
