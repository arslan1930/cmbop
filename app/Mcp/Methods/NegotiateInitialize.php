<?php

namespace App\Mcp\Methods;

use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

class NegotiateInitialize implements Method
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $requested = $request->params['protocolVersion'] ?? null;
        $supported = $context->supportedProtocolVersions;
        $version = is_string($requested) && in_array($requested, $supported, true)
            ? $requested
            : ($supported[0] ?? '2025-06-18');

        return JsonRpcResponse::result($request->id, [
            'protocolVersion' => $version,
            'capabilities' => $context->serverCapabilities ?: (object) [],
            'serverInfo' => $context->implementation->toArray(),
            'instructions' => $context->instructions,
        ]);
    }
}
