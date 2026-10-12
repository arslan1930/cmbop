<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer MCP endpoint
    |--------------------------------------------------------------------------
    |
    | Remote Streamable HTTP URL advertised on Advertiser → API & MCP.
    | Clients such as Claude custom connectors POST JSON-RPC here.
    |
    */

    'customer_path' => '/api/mcp/customer',

    'customer_sse_path' => '/api/mcp/customer/sse',

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | OAuth dynamic clients may use these redirect URI prefixes. "*" allows
    | Claude.ai, Cursor, ChatGPT, and local inspectors to register themselves.
    |
    */

    'redirect_domains' => [
        '*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients use private-use URI schemes (RFC 8252).
    |
    */

    'custom_schemes' => [
        'claude',
        'cursor',
        'vscode',
        'chatgpt',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | RFC 8414 issuer. Null uses the current request origin (APP_URL).
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tokens
    |--------------------------------------------------------------------------
    */

    'access_token_ttl' => (int) env('MCP_ACCESS_TOKEN_TTL', 60 * 60 * 24 * 30),

    'refresh_token_ttl' => (int) env('MCP_REFRESH_TOKEN_TTL', 60 * 60 * 24 * 90),

    'auth_code_ttl' => (int) env('MCP_AUTH_CODE_TTL', 600),

    /*
    |--------------------------------------------------------------------------
    | Tool Search (laravel/mcp)
    |--------------------------------------------------------------------------
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    'clients' => [],

];
