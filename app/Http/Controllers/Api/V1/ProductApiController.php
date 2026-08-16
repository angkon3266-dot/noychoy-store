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
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->catalog->present($this->catalog->updateProduct($model, $data))]);
    }

    protected function missing()
    {
        return response()->json([
            'error' => 'not_found',
            'message' => 'No product matches that id, slug or SKU.',
        ], 404);
    }
}
