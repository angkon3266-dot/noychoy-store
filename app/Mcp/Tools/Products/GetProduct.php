<?php

namespace App\Mcp\Tools\Products;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetProduct extends ProductTool
{
    protected string $name = 'get_product';

    protected string $description = 'Fetch one product in full by id, slug or exact SKU: descriptions, SEO fields, stock, options and variants, and every image with its id.';

    public function schema(JsonSchema $schema): array
    {
        return ['product' => self::productRef($schema)];
    }

    protected function run(Request $request): Response
    {
        return $this->json($this->catalog()->present($this->product($request)));
    }
}
