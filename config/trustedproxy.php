<?php

return [
    // Render containers receive public traffic only through the platform edge.
    'proxies' => env('APP_ENV', 'production') === 'production'
        ? (env('TRUSTED_PROXIES') ?: (env('RENDER', false) ? '*' : null))
        : null,
];
