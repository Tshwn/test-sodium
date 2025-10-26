<?php

return [
    'issuer' => env('OIDC_ISSUER'),
    'discovery' => env('OIDC_DISCOVERY'),
    'client_id' => env('OIDC_CLIENT_ID'),
    'expected_aud' => env('OIDC_EXPECTED_AUD', env('OIDC_CLIENT_ID')),
    'cache_ttl' => (int) env('OIDC_CACHE_TTL', 300),
    'leeway' => (int) env('OIDC_LEEWAY', 60),
    'allowed_algs' => explode(',', env('OIDC_ALLOWED_ALGS', 'RS256,ES256')),
    'token_ttl' => (int) env('OIDC_TOKEN_TTL', 3600),
];
