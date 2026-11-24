<?php

namespace App\Mcp\Tools\Products;

use App\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
class UploadProductImage extends ProductTool
{
    protected string $name = 'upload_product_image';

    protected string $description = 'Add a photo to a product from a publicly reachable image URL. The store downloads it, converts it to WebP, applies the shop watermark if that is switched on, and makes the responsive variant — exactly as an upload in the admin panel. The first image of a product becomes its main photo automatically.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'product' => self::productRef($schema),
            'image_url' => $schema->string()->description('Public http(s) URL of the image.')->required(),
            'alt' => $schema->string()->description('Alt text for accessibility and SEO.'),
            'primary' => $schema->boolean()->description('Make this the main product photo.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $url = (string) $this->need($request, 'image_url');
        if (! preg_match('#^https?://#i', $url)) {
            throw new ToolError('image_url must be a public http(s) URL.');
        }

        $product = $this->product($request);
        $image = $this->catalog()->addImage($product, $url, (bool) $request->get('primary', false), $request->get('alt'));

        return $this->json([
            'uploaded_image_id' => $image->id,
            'url' => $image->url,
            'product' => $this->catalog()->present($product->fresh(['images', 'variants', 'category'])),
        ]);
    }
}
