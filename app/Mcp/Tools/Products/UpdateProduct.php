<?php

namespace App\Mcp\Tools\Products;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateProduct extends ProductTool
{
    protected string $name = 'update_product';

    protected string $description = 'Change a product\'s details: name, price, descriptions, SEO title/description, category, stock, tags, flags or status. Only the fields you pass are touched. As in the admin panel, shoppers watching a published product get a web-push notification when it comes back in stock or its price drops.';

    public function schema(JsonSchema $schema): array
    {
        return ['product' => self::productRef($schema)] + self::productFields($schema);
    }

    protected function run(Request $request): Response
    {
        $product = $this->product($request);
        $fields = $this->withCategory(array_diff_key($request->all(), ['product' => true]));

        return $this->json($this->catalog()->present($this->catalog()->updateProduct($product, $fields)));
    }
}
