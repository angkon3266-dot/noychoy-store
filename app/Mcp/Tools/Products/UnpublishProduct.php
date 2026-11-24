<?php

namespace App\Mcp\Tools\Products;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UnpublishProduct extends ProductTool
{
    protected string $name = 'unpublish_product';

    protected string $description = 'Take a product off the storefront by setting it back to draft. Nothing is deleted; publish_product brings it back.';

    public function schema(JsonSchema $schema): array
    {
        return ['product' => self::productRef($schema)];
    }

    protected function run(Request $request): Response
    {
        return $this->json($this->catalog()->summary(
            $this->catalog()->updateProduct($this->product($request), ['status' => 'draft'])
        ));
    }
}
