<?php

namespace App\Mcp\Tools\Products;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SearchProducts extends ProductTool
{
    protected string $name = 'search_products';

    protected string $description = 'List or search products by name, SKU or slug, newest first. Returns a compact summary per product (id, price, status, stock, category, main image); call get_product for the full record.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Text to match against name, SKU or slug. Omit to list the newest.'),
            'status' => $schema->string()->enum(['published', 'draft', 'any'])->description('Default any.'),
            'limit' => $schema->integer()->description('Max results, 1–100 (default 20).'),
        ];
    }

    protected function run(Request $request): Response
    {
        return $this->json(['products' => $this->catalog()->search(
            $request->get('query'),
            (int) ($request->get('limit') ?? 20),
            false,
            $request->get('status'),
            full: false,
        )]);
    }
}
