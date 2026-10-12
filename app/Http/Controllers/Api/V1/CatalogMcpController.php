<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogUrlQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CatalogMcpController extends Controller
{
    public function __construct(
        private readonly CatalogSearchController $catalog,
    ) {}

    public function discover(Request $request): JsonResponse
    {
        if (str_contains((string) $request->header('Accept', ''), 'text/event-stream')
            && ! str_contains((string) $request->header('Accept', ''), 'application/json')) {
            return $this->mcpHeaders(response()->json([
                'status' => 'error',
                'message' => 'SSE is not used. POST JSON-RPC to this URL.',
            ], 405));
        }

        return $this->mcpHeaders(response()->json([
            'status' => 'ok',
            'name' => 'seolinkbuildings-catalog',
            'message' => 'Do not open this URL in the browser. In Cursor add a remote MCP URL with Authorization: Bearer. Then POST JSON-RPC.',
            'methods' => ['initialize', 'ping', 'tools/list', 'tools/call'],
            'tool' => 'catalog_search',
        ]));
    }

    public function options(): JsonResponse
    {
        return $this->mcpHeaders(response()->json(['status' => 'ok']));
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        if ($payload === []) {
            $decoded = json_decode((string) $request->getContent(), true);
            $payload = is_array($decoded) ? $decoded : $request->all();
        }
        if (! is_array($payload) || ($payload['jsonrpc'] ?? '') !== '2.0' || ! isset($payload['method'])) {
            return $this->mcpHeaders($this->rpcError(null, -32600, 'Invalid request.'));
        }

        $id = $payload['id'] ?? null;
        $method = (string) $payload['method'];
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        if (str_starts_with($method, 'notifications/')) {
            return $this->mcpHeaders(response()->json(new \stdClass, 202));
        }

        return $this->mcpHeaders(match ($method) {
            'initialize' => $this->rpcResult($id, [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => [
                    'name' => 'seolinkbuildings-catalog',
                    'version' => '1.0.0',
                ],
            ]),
            'ping' => $this->rpcResult($id, (object) []),
            'tools/list' => $this->rpcResult($id, [
                'tools' => [$this->catalogSearchTool()],
            ]),
            'tools/call' => $this->callTool($request, $id, $params),
            default => $this->rpcError($id, -32601, 'Method not found.'),
        });
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function callTool(Request $request, mixed $id, array $params): JsonResponse
    {
        $name = (string) ($params['name'] ?? '');
        if ($name !== 'catalog_search') {
            return $this->rpcError($id, -32602, 'Only catalog_search is available. Orders stay on the website.');
        }

        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $query = [];
        foreach (array_merge(CatalogUrlQuery::KEYS, ['website']) as $key) {
            if (array_key_exists($key, $args) && $args[$key] !== '' && $args[$key] !== null) {
                $query[$key] = $args[$key];
            }
        }

        $inner = Request::create('/api/v1/catalog', 'GET', $query);
        $user = $request->user();
        $inner->setUserResolver(static fn () => $user);
        if ($user !== null) {
            Auth::setUser($user);
        }

        $previous = app('request');
        app()->instance('request', $inner);
        try {
            $response = $this->catalog->index($inner);
        } finally {
            app()->instance('request', $previous);
        }
        $body = $response->getData(true);

        return $this->rpcResult($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($body, JSON_UNESCAPED_SLASHES) ?: '{}',
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogSearchTool(): array
    {
        return [
            'name' => 'catalog_search',
            'description' => 'Search the live advertiser catalog. Read-only. Orders stay on the website.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string'],
                    'category' => ['type' => 'string'],
                    'country' => ['type' => 'string'],
                    'language' => ['type' => 'string'],
                    'price_min' => ['type' => 'number'],
                    'price_max' => ['type' => 'number'],
                    'da_min' => ['type' => 'integer'],
                    'da_max' => ['type' => 'integer'],
                    'dr_min' => ['type' => 'integer'],
                    'dr_max' => ['type' => 'integer'],
                    'traffic_min' => ['type' => 'integer'],
                    'traffic_max' => ['type' => 'integer'],
                    'website' => ['type' => 'string', 'description' => 'Optional advertiser site URL. Fills empty country/language/category only.'],
                    'page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer', 'enum' => [10, 20, 25, 50]],
                ],
            ],
        ];
    }

    private function rpcResult(mixed $id, mixed $result): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    private function rpcError(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $code === -32600 ? 400 : 200);
    }

    private function mcpHeaders(JsonResponse $response): JsonResponse
    {
        return $response
            ->header('MCP-Protocol-Version', '2024-11-05')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, MCP-Protocol-Version, Accept');
    }
}
