<?php

namespace App\Mcp\Methods;

use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

class ListMarketplaceTools implements Method
{
    /**
     * @var list<string>
     */
    private const ANNOTATION_KEYS = [
        'title',
        'readOnlyHint',
        'destructiveHint',
        'idempotentHint',
        'openWorldHint',
    ];

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $tools = $context->tools()
            ->map(fn (Tool $tool): array => $this->present($tool))
            ->values()
            ->all();

        return JsonRpcResponse::result($request->id, [
            'tools' => $tools,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Tool $tool): array
    {
        $raw = $tool->toArray();
        $name = (string) ($raw['name'] ?? $tool->name());

        $schema = is_array($raw['inputSchema'] ?? null) ? $raw['inputSchema'] : [];
        $schema['type'] = 'object';
        $properties = $schema['properties'] ?? null;
        if (! is_array($properties) || $properties === [] || array_is_list($properties)) {
            $schema['properties'] = (object) [];
        }

        $annotations = [];
        foreach ($raw['annotations'] ?? [] as $key => $value) {
            if (is_string($key) && in_array($key, self::ANNOTATION_KEYS, true)) {
                $annotations[$key] = $value;
            }
        }

        $meta = is_array($raw['_meta'] ?? null) ? $raw['_meta'] : [];
        $meta['anthropic/alwaysLoad'] = true;

        $presented = [
            'name' => $name,
            'description' => (string) ($raw['description'] ?? $tool->description()),
            'inputSchema' => $schema,
            '_meta' => $meta,
        ];

        if (is_string($raw['title'] ?? null) && $raw['title'] !== '') {
            $presented['title'] = $raw['title'];
        }

        if ($annotations !== []) {
            $presented['annotations'] = $annotations;
        }

        return $presented;
    }
}
