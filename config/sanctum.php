<?php

/*
 * API tokens only. Stateful (cookie/session) authentication is disabled: no frontend, no CSRF cookie.
 */
return [
    // No stateful domains: requests are never authenticated from a session cookie.
    'stateful' => [],

    // No session guards are consulted; only the bearer token is.
    'guard' => [],

    // Staff tokens expire (minutes). Null would mean never.
    'expiration' => env('SANCTUM_EXPIRATION_MINUTES', 720),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => null,
        'encrypt_cookies' => null,
        'validate_csrf_token' => null,
    ],
];
