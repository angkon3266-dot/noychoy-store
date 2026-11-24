<?php

namespace App\Mcp\Tools\Products;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteProductImage extends ProductTool
{
    protected string $name = 'delete_product_image';

    protected string $description = 'DESTRUCTIVE — removes an image from a product and deletes its files; it cannot be recovered. If it was the main photo, the next image takes over.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'product' => self::productRef($schema),
            'image_id' => $schema->integer()->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $product = $this->product($request);
        $this->catalog()->deleteImage($product, $this->image($product, $request));

        return $this->json($this->catalog()->present($product->fresh(['images', 'variants', 'category'])));
    }
}
