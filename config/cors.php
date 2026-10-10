<?php

$configuredOrigins = env('CORS_ALLOWED_ORIGINS') ?: env('FRONTEND_URL', 'http://localhost:5173');
$allowedOrigins = array_values(array_unique(array_filter(
    array_map(fn (string $origin): string => rtrim(trim($origin), '/'), explode(',', $configuredOrigins)),
    fn (string $origin): bool => filter_var($origin, FILTER_VALIDATE_URL) !== false
        && in_array(parse_url($origin, PHP_URL_SCHEME), ['http', 'https'], true)
        && ! str_contains($origin, '*')
        && ! parse_url($origin, PHP_URL_PATH)
        && ! parse_url($origin, PHP_URL_QUERY)
        && ! parse_url($origin, PHP_URL_FRAGMENT)
        && ! parse_url($origin, PHP_URL_USER)
        && ! parse_url($origin, PHP_URL_PASS),
)));

return [
    'paths' => [
        'api/*',
        'login',
        'logout',
        'register',
        'forgot-password',
        'reset-password',
        'email/*',
        'two-factor-challenge',
        'user/*',
        'broadcasting/auth',
        'passkeys/*',
    ],
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-CSRF-TOKEN',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'X-Socket-Id',
    ],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 600,
    'supports_credentials' => true,
];
