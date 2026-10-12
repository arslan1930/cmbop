<?php

return [

    'paths' => [
        'api/*',
        'oauth/*',
        '.well-known/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Mcp-Session-Id',
        'WWW-Authenticate',
    ],

    'max_age' => 86400,

    'supports_credentials' => false,

];
