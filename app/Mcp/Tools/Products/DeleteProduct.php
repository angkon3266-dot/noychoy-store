<?php

namespace App\Mcp\Tools\Products;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteProduct extends ProductTool
{
    protected string $name = 'delete_product';

    protected string $description = 'DESTRUCTIVE — ask the store owner first. Deletes (archives) a product: it disappears from the storefront and the admin product list immediately. It is a soft delete, exactly like Delete in the admin panel: past orders keep their line items and a developer can restore it, but there is no undo button in the admin. To hide a product temporarily use unpublish_product instead. Requires confirm=true.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'product' => self::productRef($schema),
            'confirm' => $schema->boolean()->description('Must be true — only after the owner agreed.')->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $this->requireConfirm($request, 'delete this product');
        $product = $this->product($request);
        $this->catalog()->deleteProduct($product);

        return $this->json(['deleted' => true, 'id' => $product->id, 'name' => $product->name, 'soft_deleted' => true]);
    }
}
