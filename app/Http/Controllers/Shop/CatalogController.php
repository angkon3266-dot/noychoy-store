<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::published()
            ->with('images', 'approvedReviews', 'category')
            ->search($request->query('q'))
            ->when($request->query('sort'), function ($query, $sort) {
                match ($sort) {
                    'price_asc' => $query->orderBy('price'),
                    'price_desc' => $query->orderByDesc('price'),
                    'name' => $query->orderBy('name'),
                    default => $query->latest(),
                };
            }, fn ($query) => $query->latest())
            ->paginate(24)
            ->withQueryString();

        return view('shop.catalog', [
            'products' => $products,
            'title' => $request->filled('q') ? 'Search: '.$request->query('q') : 'All Jewelry',
        ]);
    }

    /** JSON type-ahead suggestions for the header search box. */
    public function suggest(Request $request)
    {
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $products = Product::published()
            ->search($term)
            ->with('images', 'approvedReviews', 'category')
            ->take(6)
            ->get();

        return response()->json($products->map(fn ($p) => [
            'name' => $p->name,
            'price' => money($p->price),
            'thumb' => $p->thumbnail,
            'url' => route('product.show', $p),
        ]));
    }

    public function category(Request $request, Category $category)
    {
        abort_unless($category->is_active, 404);

        $ids = $category->children()->pluck('id')->push($category->id);

        $products = Product::published()
            ->whereIn('category_id', $ids)
            ->with('images', 'approvedReviews', 'category')
            ->when($request->query('sort'), function ($query, $sort) {
                match ($sort) {
                    'price_asc' => $query->orderBy('price'),
                    'price_desc' => $query->orderByDesc('price'),
                    'name' => $query->orderBy('name'),
                    default => $query->latest(),
                };
            }, fn ($query) => $query->latest())
            ->paginate(24)
            ->withQueryString();

        return view('shop.catalog', [
            'products' => $products,
            'title' => $category->name,
            'category' => $category,
        ]);
    }

    public function show(Product $product)
    {
        abort_unless($product->status === 'published', 404);

        $product->load(['images', 'variants' => fn ($q) => $q->where('is_active', true), 'category', 'approvedReviews']);
        $product->increment('views');

        // Manual cross-sells ("frequently bought together") and upsells.
        $crossSells = $product->crossSells();
        $upsells = $product->upsells();

        // "You may also like" prefers manual upsells, falls back to same-category.
        $related = $upsells->isNotEmpty()
            ? $upsells
            : Product::published()
                ->where('category_id', $product->category_id)
                ->where('id', '!=', $product->id)
                ->with('images', 'approvedReviews', 'category')
                ->take(4)->get();

        // Track recently viewed (session, max 8).
        $recent = collect(session('recently_viewed', []))
            ->reject(fn ($id) => $id === $product->id)
            ->prepend($product->id)
            ->take(8)->values()->all();
        session(['recently_viewed' => $recent]);

        // Resolve template: category override → theme default → fallback.
        $key = $product->category?->product_template ?: theme('product_template');
        $view = config('theme.product_templates.'.$key.'.view');
        if (! $view || ! view()->exists($view)) {
            $view = 'shop.templates.product.showcase';
        }

        return view($view, compact('product', 'related', 'crossSells'));
    }
}
