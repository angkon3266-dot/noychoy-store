<?php

namespace App\Mcp\Tools\Products;

use App\Mcp\ToolError;
use App\Mcp\Tools\StoreTool;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Api\CatalogApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

/**
 * Shared plumbing for the catalogue tools. Everything goes through
 * {@see CatalogApi}, which the REST API uses too, so the two surfaces cannot
 * grow different rules about stock, images or variants.
 */
abstract class ProductTool extends StoreTool
{
    protected ?string $section = 'products';

    protected function catalog(): CatalogApi
    {
        return app(CatalogApi::class);
    }

    protected function product(Request $request): Product
    {
        $ref = (string) $request->get('product', '');

        return ($ref !== '' ? $this->catalog()->find($ref) : null)
            ?: throw new ToolError('No product matches "'.$ref.'". Try search_products first.');
    }

    protected function image(Product $product, Request $request): ProductImage
    {
        $id = (int) $request->get('image_id', 0);

        return $product->images()->find($id)
            ?: throw new ToolError("Image {$id} does not belong to {$product->name}. get_product lists its images.");
    }

    /** Map an agent's `category` (id / slug / name) onto `category_id`. */
    protected function withCategory(array $args): array
    {
        if (filled($args['category'] ?? null)) {
            $cat = $this->catalog()->findCategory((string) $args['category'])
                ?: throw new ToolError('No category matches "'.$args['category'].'". Call list_categories to see them.');
            $args['category_id'] = $cat->id;
        }
        unset($args['category']);

        return $args;
    }

    protected static function productRef(JsonSchema $schema)
    {
        return $schema->string()->description('Product id, slug or exact SKU.')->required();
    }

    /** The editable product fields, shared by create_product and update_product. */
    protected static function productFields(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string(),
            'price' => $schema->number()->description('Regular price in the store currency (BDT).'),
            'compare_at_price' => $schema->number()->description('Was-price, for showing a discount. Leave it out when the discount comes from an offer: while an offer covers the product the store strikes through the regular price instead and ignores this.'),
            'category' => $schema->string()->description('Category id, slug or exact name (see list_categories).'),
            'sku' => $schema->string(),
            'short_description' => $schema->string()->description('One or two sentences shown under the title.'),
            'description' => $schema->string()->description('Full description (plain text, newlines kept).'),
            'stock_quantity' => $schema->integer()->description('Units on hand, for a product without variants.'),
            'manage_stock' => $schema->boolean()->description('Track stock_quantity and show the product as sold out at 0.'),
            'status' => $schema->string()->enum(['published', 'draft']),
            'is_featured' => $schema->boolean(),
            'is_bestseller' => $schema->boolean(),
            'is_preorder' => $schema->boolean(),
            'preorder_note' => $schema->string(),
            'tags' => $schema->array()->items($schema->string()),
            'weight' => $schema->number()->description('Weight in grams.'),
            'meta_title' => $schema->string()->description('SEO title (search results / browser tab).'),
            'meta_description' => $schema->string()->description('SEO description, about 150 characters.'),
        ];
    }
}
