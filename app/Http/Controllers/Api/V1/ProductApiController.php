<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Api\CatalogApi;
use Illuminate\Http\Request;

class ProductApiController extends Controller
{
    public function __construct(private readonly CatalogApi $catalog) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->catalog->search(
                $request->query('q'),
                (int) $request->query('limit', 20),
                $request->boolean('published_only'),
            ),
        ]);
    }

    public function show(string $product)
    {
        $model = $this->catalog->find($product);

        return $model
            ? response()->json(['data' => $this->catalog->present($model)])
            : $this->missing();
    }

    public function update(Request $request, string $product)
    {
        $model = $this->catalog->find($product);
        if (! $model) {
            return $this->missing();
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'compare_at_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:published,draft'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_bestseller' => ['sometimes', 'boolean'],
            'manage_stock' => ['sometimes', 'boolean'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:80'],
            'category' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'tags' => ['sometimes', 'nullable'],
            'weight' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if ($resolved = $this->resolveCategory($data)) {
            return $resolved;
        }

        return response()->json(['data' => $this->catalog->present($this->catalog->updateProduct($model, $data))]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200'],
            'sku' => ['nullable', 'string', 'max:80'],
            'price' => ['required', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'manage_stock' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:published,draft'],
            'is_featured' => ['nullable', 'boolean'],
            'is_bestseller' => ['nullable', 'boolean'],
            'tags' => ['nullable'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:300'],
        ]);

        if ($resolved = $this->resolveCategory($data)) {
            return $resolved;
        }

        return response()->json(['data' => $this->catalog->present($this->catalog->createProduct($data))], 201);
    }

    public function destroy(string $product)
    {
        $model = $this->catalog->find($product);
        if (! $model) {
            return $this->missing();
        }

        $this->catalog->deleteProduct($model);

        return response()->json(['data' => ['deleted' => true, 'id' => $model->id, 'restorable' => true]]);
    }

    public function categories()
    {
        return response()->json(['data' => $this->catalog->categories()]);
    }

    /** Turn a `category` (slug/name) into `category_id`; returns a 422 response when unknown. */
    protected function resolveCategory(array &$data)
    {
        if (! empty($data['category']) && empty($data['category_id'])) {
            $cat = $this->catalog->findCategory($data['category']);
            if (! $cat) {
                return response()->json([
                    'error' => 'unknown_category',
                    'message' => 'No category matches "'.$data['category'].'". Call GET /api/v1/categories to see them.',
                ], 422);
            }
            $data['category_id'] = $cat->id;
        }
        unset($data['category']);

        return null;
    }

    protected function missing()
    {
        return response()->json([
            'error' => 'not_found',
            'message' => 'No product matches that id, slug or SKU.',
        ], 404);
    }
}
