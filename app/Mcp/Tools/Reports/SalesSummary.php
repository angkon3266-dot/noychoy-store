<?php

namespace App\Mcp\Tools\Reports;

use App\Mcp\Tools\StoreTool;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\DashboardAnalytics;
use App\Support\DateRange;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** The dashboard's own numbers (DashboardAnalytics), so Claude and the dashboard agree. */
#[IsReadOnly]
class SalesSummary extends StoreTool
{
    protected ?string $section = 'dashboard';

    protected string $name = 'sales_summary';

    protected string $description = 'Sales for a period, as the admin dashboard counts them (cancelled, returned and partially delivered orders are not sales): revenue, cost and profit, order count, average order value, change against the previous period, orders by status, and the best-selling products.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->enum(array_keys(DateRange::PRESETS))->description('Default 30d. Days are Bangladesh time.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $range = DateRange::preset((string) ($request->get('period') ?? '30d'));
        $cmp = app(DashboardAnalytics::class)->periodComparison($range);

        $byStatus = $range->constrain(Order::query())
            ->selectRaw('status, COUNT(*) as n, COALESCE(SUM(total),0) as total')
            ->groupBy('status')->get()
            ->mapWithKeys(fn ($r) => [$r->status => ['orders' => (int) $r->n, 'total' => (float) $r->total]]);

        $top = $range->constrain(
            OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereNull('orders.deleted_at')->whereNotIn('orders.status', Order::NOT_SALES),
            'orders.created_at',
        )
            ->selectRaw('order_items.product_id, MAX(order_items.name) as name, SUM(order_items.quantity) as units, SUM(order_items.subtotal) as revenue')
            ->groupBy('order_items.product_id')->orderByDesc('units')->limit(10)->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'name' => $r->name, 'units' => (int) $r->units, 'revenue' => (float) $r->revenue]);

        return $this->json([
            'period' => $range->label,
            'currency' => config('store.currency', 'BDT'),
            'revenue' => $cmp['current']['revenue'],
            'cost' => $cmp['current']['cost'],
            'profit' => $cmp['current']['profit'],
            'margin_percent' => $cmp['current']['margin'],
            'orders' => $cmp['current']['orders'],
            'items_sold' => $cmp['current']['items'],
            'average_order_value' => $cmp['aov'],
            'discount_given' => $cmp['discount_given'] ?? null,
            'revenue_change_percent' => $cmp['revenue_change'],
            'orders_by_status' => $byStatus,
            'top_products' => $top,
        ]);
    }
}
