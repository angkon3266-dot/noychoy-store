<?php

namespace App\Mcp\Tools\Products;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class PublishProduct extends ProductTool
{
    protected string $name = 'publish_product';

    protected string $description = 'Make a product live on the storefront (status=published). Shoppers can see and buy it immediately.';

    public function schema(JsonSchema $schema): array
    {
        return ['product' => self::productRef($schema)];
    }

    protected function run(Request $request): Response
    {
        return $this->json($this->catalog()->summary(
            $this->catalog()->updateProduct($this->product($request), ['status' => 'published'])
        ));
    }
}
