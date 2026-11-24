<?php

namespace App\Mcp\Tools\Orders;

use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListOrders extends OrderTool
{
    protected string $name = 'list_orders';

    protected string $description = 'List orders, newest first. Filter by status, by a search (order number, customer phone or name) and by how many days back. Returns a summary per order; call get_order for items, address and history.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(array_keys(Order::STATUSES)),
            'search' => $schema->string()->description('Order number, phone (any format) or customer name.'),
            'days' => $schema->integer()->description('Only orders placed in the last N days.'),
            'limit' => $schema->integer()->description('1–100, default 20.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $search = trim((string) $request->get('search', ''));
        $digits = preg_replace('/\D/', '', $search);

        $orders = Order::query()->withCount('items')
            ->status($request->get('status'))
            ->when($request->get('days'), fn ($q, $days) => $q->where('created_at', '>=', now()->subDays((int) $days)))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->when(strlen($digits) >= 6, fn ($p) => $p->orWhere('customer_phone', 'like', '%'.substr(bd_phone($digits), -10).'%'))))
            ->latest('id')
            ->take(max(1, min(100, (int) ($request->get('limit') ?? 20))))
            ->get();

        return $this->json([
            'count' => $orders->count(),
            'orders' => $orders->map(fn (Order $o) => self::summary($o))->all(),
        ]);
    }
}
