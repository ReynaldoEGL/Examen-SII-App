<?php

return [
    'sii' => [
        'base_url' => env('SII_API_BASE_URL', 'https://sii.celaya.tecnm.mx/api'),
        'timeout' => (int) env('SII_TIMEOUT', 15),
    ],
    'internal' => [
        'shared_secret' => env('INTERNAL_SHARED_SECRET'),
    ],
    // Secreto exclusivo de Laravel. No compartir con Next.js ni con el SII.
    'pseudonym_key' => env('PSEUDONYM_KEY'),
    'admin_initial' => [
        'email' => env('ADMIN_INITIAL_EMAIL'),
        'password' => env('ADMIN_INITIAL_PASSWORD'),
    ],
];
