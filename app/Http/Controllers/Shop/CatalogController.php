<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductLove;
use App\Services\Meta\MetaTrackingService;
use App\Services\StorefrontFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public function __construct(
        protected StorefrontFilters $filters,
        protected MetaTrackingService $tracking,
    ) {}

    public function index(Request $request)
    {
        $base = Product::published()->search($request->query('q'));

        return view('shop.catalog', [
            'products' => $this->paginate($base, $request),
            'filters' => $this->filters->groups($request, Product::published()->search($request->query('q')), 'shop'),
            'title' => $request->filled('q') ? 'Search: '.$request->query('q') : 'All Jewelry',
        ]);
    }

    /** Apply filters + sort to a base query and paginate. */
    protected function paginate(Builder $base, Request $request)
    {
        $query = (clone $base)->with('images', 'approvedReviews', 'category');
        $this->filters->apply($query, $request);
        $this->applySort($query, $request);

        // Fixed by the admin (Appearance → Catalog). No visitor override.
        $perPage = (int) theme('products_per_page', 20);
        if ($perPage < 1) {
            $perPage = 20;
        }

        return $query->paginate($perPage)->withQueryString();
    }

    protected function applySort($query, Request $request): void
    {
        $query->when($request->query('sort'), function ($q, $sort) {
            match ($sort) {
                'price_asc' => $q->orderBy('price'),
                'price_desc' => $q->orderByDesc('price'),
                'name' => $q->orderBy('name'),
                'popular' => $q->orderByDesc('views'),
                default => $q->latest(),
            };
        }, fn ($q) => $q->latest());
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

        $base = Product::published()
            ->where(fn ($q) => $q->whereIn('category_id', $ids)
                ->orWhereHas('categories', fn ($c) => $c->whereIn('categories.id', $ids)));

        return view('shop.catalog', [
            'products' => $this->paginate($base, $request),
            'filters' => $this->filters->groups($request, clone $base, 'category:'.$category->id),
            'title' => $category->name,
            'category' => $category,
        ]);
    }

    public function show(Request $request, Product $product)
    {
        abort_unless($product->status === 'published', 404);

        $product->load(['images', 'variants' => fn ($q) => $q->where('is_active', true), 'category', 'categories', 'approvedReviews']);
        $product->increment('views');

        // "Frequently bought together": real co-purchase pairs from past orders,
        // falling back to manual cross-sells, then same-category products.
        $crossSells = $this->frequentlyBoughtTogether($product);
        $upsells = $product->upsells();

        // Love reaction state for this visitor.
        $lovesCount = (int) $product->loves_count;
        $loved = false;
        if ($token = $request->cookie('visitor_token')) {
            $loved = ProductLove::where('product_id', $product->id)->where('visitor_token', $token)->exists();
        }

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

        // ViewContent — fire server-side (CAPI) and hand the SAME event id to the
        // Blade so the browser Pixel dedups against it. content_ids match the
        // catalog retailer_id via meta_content_id().
        $vcEventId = MetaTrackingService::newEventId('ViewContent');
        $this->tracking->viewContent($product, $vcEventId, $this->trackingUser($request));

        // Shared Alpine config for the product page (built once, used by every template).
        $pp = [
            'id' => $product->id,
            'name' => $product->name,
            'price' => (float) $product->price,
            'hasVariants' => (bool) $product->has_variants,
            'image' => $product->images->first()?->url ?? '',
            'attributes' => $product->options ?? [],   // [{name, values:[]}]
            'variants' => $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'attrs' => $v->attributes ?? [],
                'price' => (float) ($v->price ?? $product->price),
                'stock' => (int) $v->stock_quantity,
            ])->values(),
            'offers' => $product->offerTiers(),
        ];

        // Resolve template: category override → theme default → fallback.
        $key = $product->category?->product_template ?: theme('product_template');
        $view = config('theme.product_templates.'.$key.'.view');
        if (! $view || ! view()->exists($view)) {
            $view = 'shop.templates.product.showcase';
        }

        return view($view, compact('product', 'related', 'crossSells', 'pp', 'lovesCount', 'loved', 'vcEventId'));
    }

    /**
     * Identifiable fields for CAPI user_data, taken from the logged-in customer
     * when present. Hashing happens inside MetaTrackingService; guests just yield
     * an empty array (IP / user-agent / fbp still provide match quality).
     *
     * @return array<string,?string>
     */
    protected function trackingUser(Request $request): array
    {
        $c = $request->user('customer') ?? auth('customer')->user();

        return $c ? ['em' => $c->email, 'ph' => $c->phone, 'fn' => $c->name] : [];
    }

    /**
     * Products most often purchased in the same orders as $product.
     * Falls back to the admin's manual cross-sells, then same-category items.
     */
    protected function frequentlyBoughtTogether(Product $product, int $limit = 4): \Illuminate\Support\Collection
    {
        $orderIds = OrderItem::where('product_id', $product->id)->pluck('order_id');

        $ids = $orderIds->isEmpty() ? collect() : OrderItem::whereIn('order_id', $orderIds)
            ->where('product_id', '!=', $product->id)
            ->whereNotNull('product_id')
            ->select('product_id', DB::raw('COUNT(*) as c'))
            ->groupBy('product_id')
            ->orderByDesc('c')
            ->limit($limit)
            ->pluck('product_id');

        if ($ids->isNotEmpty()) {
            $found = Product::published()->whereIn('id', $ids)->with('images', 'approvedReviews', 'category')->get()
                ->sortBy(fn ($p) => $ids->search($p->id))->values();
            if ($found->isNotEmpty()) {
                return $found;
            }
        }

        // Fallback 1: manual cross-sells set on the product.
        $manual = $product->crossSells();
        if ($manual->isNotEmpty()) {
            return $manual;
        }

        // Fallback 2: other products from the same category.
        return Product::published()
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->with('images', 'approvedReviews', 'category')
            ->take($limit)->get();
    }
}
