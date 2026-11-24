<?php

namespace App\Mcp\Tools\Products;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class SetPrimaryImage extends ProductTool
{
    protected string $name = 'set_primary_image';

    protected string $description = 'Make one of a product\'s existing images its main photo (the one on cards and in search).';

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
        $this->catalog()->setPrimary($product, $this->image($product, $request));

        return $this->json($this->catalog()->present($product->fresh(['images', 'variants', 'category'])));
    }
}
