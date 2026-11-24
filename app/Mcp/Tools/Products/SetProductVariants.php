<?php

namespace App\Mcp\Tools\Products;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsDestructive]
#[IsIdempotent]
class SetProductVariants extends ProductTool
{
    protected string $name = 'set_product_variants';

    protected string $description = 'REPLACES all of a product\'s options and variants (e.g. Size 6/7/8, Colour Gold/Silver) with the list you pass — the same rebuild the admin form does on save, so existing variant ids change. Pass the complete list every time. An empty variants list turns it back into a simple product. The product\'s stock becomes the sum of its variants\' stock. Call get_product first to see the current variants.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'product' => self::productRef($schema),
            'options' => $schema->array()->description('Option definitions, e.g. [{"name":"Size","values":["6","7","8"]}]. Optional: derived from the variants when omitted.')
                ->items($schema->object([
                    'name' => $schema->string()->required(),
                    'values' => $schema->array()->items($schema->string())->required(),
                ])),
            'variants' => $schema->array()->required()->description('Every variant, e.g. [{"attributes":{"Size":"7"},"stock":3,"price":1450}]. price empty = the product price.')
                ->items($schema->object([
                    'attributes' => $schema->object()->description('Option name → value, e.g. {"Size":"7","Colour":"Gold"}.')->required(),
                    'stock' => $schema->integer(),
                    'price' => $schema->number(),
                    'compare_at_price' => $schema->number(),
                    'sku' => $schema->string(),
                    'image_id' => $schema->integer()->description('One of this product\'s image ids, shown when the variant is picked.'),
                ])),
        ];
    }

    protected function run(Request $request): Response
    {
        $product = $this->product($request);
        $variants = $request->get('variants');
        if (! is_array($variants)) {
            $this->need($request, 'variants');
        }

        return $this->json($this->catalog()->present($this->catalog()->setVariants(
            $product,
            (array) ($request->get('options') ?? []),
            array_values(array_filter((array) $variants, 'is_array')),
        )));
    }
}
