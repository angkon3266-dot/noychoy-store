<?php

namespace App\Mcp\Tools\Reports;

use App\Mcp\Tools\StoreTool;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class StockSummary extends StoreTool
{
    protected ?string $section = 'products';

    protected string $name = 'stock_summary';

    protected string $description = 'Inventory at a glance: how many products are published / draft, which published products are sold out, which are running low (at or below the threshold, default 2), and which size/colour variants are out — so you can restock or unpublish.';

    public function schema(JsonSchema $schema): array
    {
        return ['low_threshold' => $schema->integer()->description('Units at or below which stock counts as low. Default 2.')];
    }

    protected function run(Request $request): Response
    {
        $low = max(0, (int) ($request->get('low_threshold') ?? 2));
        $row = fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'stock' => (int) $p->stock_quantity];

        $tracked = Product::query()->where('status', 'published')->where('manage_stock', true);

        return $this->json([
            'products' => [
                'published' => Product::where('status', 'published')->count(),
                'draft' => Product::where('status', 'draft')->count(),
            ],
            'sold_out' => (clone $tracked)->where('stock_quantity', '<=', 0)->orderBy('name')->get()->map($row)->all(),
            'low_stock' => (clone $tracked)->whereBetween('stock_quantity', [1, max(1, $low)])->orderBy('stock_quantity')->get()->map($row)->all(),
            'sold_out_variants' => ProductVariant::query()->with('product:id,name,status')
                ->where('stock_quantity', '<=', 0)->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->where('status', 'published')->where('has_variants', true))
                ->limit(100)->get()
                ->map(fn ($v) => ['product_id' => $v->product_id, 'product' => $v->product?->name, 'variant' => $v->attributes])->all(),
            'units_on_hand' => (int) Product::where('status', 'published')->where('manage_stock', true)->sum('stock_quantity'),
        ]);
    }
}
