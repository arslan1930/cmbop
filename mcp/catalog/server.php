#!/usr/bin/env php
<?php

/**
 * Local stdio MCP for Cursor / Claude Desktop.
 * Forwards JSON-RPC to POST /api/v1/mcp on Laravel (Hostinger or artisan serve).
 *
 *   CATALOG_API_URL=http://127.0.0.1:8000/api/v1/mcp
 *   CATALOG_API_TOKEN=slb_…
 *   php mcp/catalog/server.php
 */

$url = rtrim((string) (getenv('CATALOG_API_URL') ?: 'https://seolinkbuildings.com/api/v1/mcp'), '/');
$token = trim((string) getenv('CATALOG_API_TOKEN'));

if ($token === '' || ! str_starts_with($token, 'slb_')) {
    fwrite(STDERR, "Set CATALOG_API_TOKEN to an Advertiser → API key (slb_…).\n");
    exit(1);
}

while (! feof(STDIN)) {
    $headers = '';
    while (($line = fgets(STDIN)) !== false) {
        if ($line === "\r\n" || $line === "\n") {
            break;
        }
        $headers .= $line;
    }
    if ($headers === '' && feof(STDIN)) {
        break;
    }
    if (! preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
        continue;
    }
    $len = (int) $m[1];
    $body = $len > 0 ? fread(STDIN, $len) : '';
    if (! is_string($body) || $body === '') {
        continue;
    }

    $reply = forward($url, $token, $body);
    $out = 'Content-Length: '.strlen($reply)."\r\n\r\n".$reply;
    fwrite(STDOUT, $out);
    fflush(STDOUT);
}

function forward(string $url, string $token, string $body): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if (! is_string($raw) || $raw === '') {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32603, 'message' => $err !== '' ? $err : 'Empty MCP response.'],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    return $raw;
}
