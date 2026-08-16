<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Api\CatalogApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product images over HTTP. Accepts either a multipart file upload or a JSON
 * body with a URL, because the two callers that matter differ: a script has a
 * file on disk, an agent usually has a link to one.
 */
class ProductImageApiController extends Controller
{
    public function __construct(private readonly CatalogApi $catalog) {}

    public function store(Request $request, string $product)
    {
        $model = $this->catalog->find($product);
        if (! $model) {
            return $this->missingProduct();
        }

        $request->validate([
            'image' => ['required_without:url', 'file', 'image', 'max:12288'],
            'url' => ['required_without:image', 'string', 'url', 'max:2000'],
            'alt' => ['nullable', 'string', 'max:255'],
            'primary' => ['nullable', 'boolean'],
        ]);

        $image = $this->catalog->addImage(
            $model,
            $request->hasFile('image') ? $request->file('image') : $request->input('url'),
            $request->boolean('primary'),
            $request->input('alt'),
        );

        return response()->json([
            'data' => [
                'id' => $image->id,
                'url' => $image->url,
                'is_primary' => (bool) $image->is_primary,
                'product' => $this->catalog->present($model->fresh('images')),
            ],
        ], 201);
    }

    public function update(Request $request, string $product, int $image)
    {
        [$model, $img, $error] = $this->resolve($product, $image);
        if ($error) {
            return $error;
        }

        $data = $request->validate([
            'primary' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('position', $data) || array_key_exists('alt', $data)) {
            $img->update(array_intersect_key($data, array_flip(['position', 'alt'])));
        }

        if (! empty($data['primary'])) {
            $this->catalog->setPrimary($model, $img);
        }

        return response()->json(['data' => $this->catalog->present($model->fresh('images'))]);
    }

    public function destroy(string $product, int $image)
    {
        [$model, $img, $error] = $this->resolve($product, $image);
        if ($error) {
            return $error;
        }

        $this->catalog->deleteImage($model, $img);

        return response()->json(['data' => $this->catalog->present($model->fresh('images'))]);
    }

    /** @return array{0:?Product,1:?ProductImage,2:?JsonResponse} */
    protected function resolve(string $product, int $imageId): array
    {
        $model = $this->catalog->find($product);
        if (! $model) {
            return [null, null, $this->missingProduct()];
        }

        // Scoped to the product on purpose: an image id from another product
        // must not be editable through this product's URL.
        $img = $model->images()->find($imageId);
        if (! $img) {
            return [null, null, response()->json([
                'error' => 'not_found',
                'message' => 'That image does not belong to this product.',
            ], 404)];
        }

        return [$model, $img, null];
    }

    protected function missingProduct()
    {
        return response()->json([
            'error' => 'not_found',
            'message' => 'No product matches that id, slug or SKU.',
        ], 404);
    }
}
