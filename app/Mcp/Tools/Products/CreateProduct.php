<?php

namespace App\Mcp\Tools\Products;

use App\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class CreateProduct extends ProductTool
{
    protected string $name = 'create_product';

    protected string $description = 'Create a new product. It is created as a DRAFT (not visible to shoppers) unless status=published is passed. Add photos with upload_product_image and sizes/colours with set_product_variants afterwards, then publish_product. Returns the new product incl. id and slug.';

    public function schema(JsonSchema $schema): array
    {
        $fields = self::productFields($schema);
        $fields['name'] = $fields['name']->required();
        $fields['price'] = $fields['price']->required();

        return $fields;
    }

    protected function run(Request $request): Response
    {
        $args = $request->all();
        if (blank($args['name'] ?? null) || ! isset($args['price'])) {
            throw new ToolError('create_product needs at least name and price.');
        }

        return $this->json($this->catalog()->present(
            $this->catalog()->createProduct($this->withCategory($args))->load(['variants', 'category'])
        ));
    }
}
