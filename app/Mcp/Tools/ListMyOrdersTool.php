<?php

namespace App\Mcp\Tools;

use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_my_orders')]
#[Title('List my orders')]
#[Description('The signed-in advertiser\'s recent orders only. Status, EUR totals, and placement hosts — never publisher emails or payout data.')]
#[IsReadOnly]
class ListMyOrdersTool extends Tool
{
    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('How many orders to return (1–50, default 10).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Sign in as an advertiser to list orders.');
        }

        $limit = max(1, min(50, (int) ($request->get('limit') ?? 10)));

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->with(['items' => static function ($query) {
                $query->select([
                    'id',
                    'order_id',
                    'site_id',
                    'site_name',
                    'site_url',
                    'price',
                    'publisher_status',
                ]);
            }])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $rows = [];
        foreach ($orders as $order) {
            $items = [];
            foreach ($order->items as $item) {
                $items[] = [
                    'site_name' => (string) ($item->site_name ?? ''),
                    'host' => $this->host((string) ($item->site_url ?? '')),
                    'price_eur' => round((float) ($item->price ?? 0), 2),
                    'status' => (string) ($item->publisher_status ?? ''),
                ];
            }

            $rows[] = [
                'order_number' => (string) ($order->order_number ?? $order->id),
                'status' => (string) ($order->status ?? ''),
                'total_eur' => round((float) ($order->total_amount ?? 0), 2),
                'currency' => 'EUR',
                'created_at' => optional($order->created_at)?->toIso8601String(),
                'items' => $items,
            ];
        }

        return Response::json([
            'orders' => $rows,
            'count' => count($rows),
            'currency' => 'EUR',
        ]);
    }

    private function host(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' ? $host : $url;
    }
}
