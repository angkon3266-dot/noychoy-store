<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\CollectionService;
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

        return $this->renderCatalog($request, $base, 'shop',
            $request->filled('q') ? 'Search: '.$request->query('q') : 'All Jewelry');
    }

    /** Curated best-sellers — the products you've flagged as bestsellers. */
    public function bestSellers(Request $request)
    {
        $base = Product::published()->bestsellers()->search($request->query('q'));

        return $this->renderCatalog($request, $base, 'shop', 'Best Sellers');
    }

    /** Shared Inertia response for the shop / best-sellers / category grids. */
    protected function renderCatalog(
        Request $request,
        Builder $base,
        string $filterKey,
        string $title,
        ?Category $category = null,
        ?string $description = null,
    ) {
        $description ??= $category?->description;

        $products = $this->paginate($base, $request);

        return \Inertia\Inertia::render('Catalog', [
            'pageTitle' => $title,
            'title' => $title,
            'description' => $description,
            'products' => [
                'data' => \App\Support\Storefront\ProductCardData::collection($products->items()),
                'links' => $products->linkCollection(),
                'total' => $products->total(),
                'current_page' => $products->currentPage(),
            ],
            'filters' => $this->filters->groups($request, clone $base, $filterKey),
            'sort' => $request->query('sort') ?: theme('default_sort', 'new'),
            'searchQuery' => $request->query('q') ?: null,
        ])->withViewData([
            'pageTitle' => $title,
            'metaDescription' => $description,
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
        // Visitor's choice wins; otherwise the admin default (Appearance → Catalog).
        $sort = $request->query('sort') ?: theme('default_sort', 'new');

        match ($sort) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'name' => $query->orderBy('name'),
            'popular' => $query->orderByDesc('views'),
            // Real units sold (cancelled/returned/deleted orders excluded) —
            // distinct from the curated is_bestseller flag.
            'best_selling' => $query->orderByDesc(
                \App\Models\OrderItem::query()
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereNull('orders.deleted_at')
                    ->whereNotIn('orders.status', ['cancelled', 'returned'])
                    ->whereColumn('order_items.product_id', 'products.id')
                    ->selectRaw('COALESCE(SUM(order_items.quantity), 0)')
            ),
            default => $query->latest(),   // 'new'
        };

        // Let the catalog view reflect the effective sort in its dropdown.
        view()->share('shopSort', $sort);
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
                ->orWhereHas('categories', fn ($c) => $c->whereIn('categories.id', $ids)))
            ->search($request->query('q'));

        return $this->renderCatalog($request, $base, 'category:'.$category->id, $category->name, $category);
    }

    /**
     * A collection page. Reuses the catalog grid wholesale — a collection is
     * just a different way of choosing which products are in the base query.
     */
    public function collection(Request $request, \App\Models\Collection $collection, CollectionService $collections)
    {
        abort_unless($collection->is_active, 404);

        $base = $collections->query($collection)->search($request->query('q'));

        return $this->renderCatalog(
            $request,
            $base,
            'collection:'.$collection->id,
            $collection->name,
            null,
            $collection->description,
        );
    }

    public function show(Request $request, Product $product)
    {
        abort_unless($product->status === 'published', 404);

        $product->load(['images', 'variants' => fn ($q) => $q->where('is_active', true), 'category', 'categories', 'approvedReviews']);

        // Count a view once per session per product — avoids a DB write on every
        // refresh and stops bots/reloads skewing the "popular" sort.
        $viewed = (array) session('viewed_products', []);
        if (! in_array($product->id, $viewed, true)) {
            // Query-builder increment: no model events (won't bust the homepage
            // cache or trigger the Meta sync observer for a mere view count).
            Product::whereKey($product->id)->increment('views');
            session()->put('viewed_products', array_slice([...$viewed, $product->id], -100));
        }

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

        // "You may also like": the admin's picks lead, then relevance fill to 5.
        $related = $this->fillRelated($product, $upsells, 5);

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
            'compare' => (float) $product->compare_at_price,
            'hasVariants' => (bool) $product->has_variants,
            'image' => $product->images->first()?->url ?? '',
            'attributes' => $product->options ?? [],   // [{name, values:[]}]
            'variants' => $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'attrs' => $v->attributes ?? [],
                'price' => (float) ($v->price ?? $product->price),
                'compare' => (float) $v->compare_at_price,
                'stock' => (int) $v->stock_quantity,
                // Variation photo — the gallery jumps to it when this variant is picked.
                'image' => $v->image_id ? $product->images->firstWhere('id', $v->image_id)?->url : null,
            ])->values(),
            'offers' => $product->offerTiers(),
        ];

        // "Recently viewed" strip (was computed inline in the Blade partial).
        $recentlyViewed = collect();
        if (theme('show_recently_viewed')) {
            $recentIds = collect(session('recently_viewed', []))->reject(fn ($id) => $id === $product->id)->take(4);
            if ($recentIds->isNotEmpty()) {
                $recentlyViewed = Product::published()->whereIn('id', $recentIds)
                    ->with('images', 'approvedReviews')->get()
                    ->sortBy(fn ($p) => $recentIds->search($p->id))->values();
            }
        }

        // One React product page replaces the Blade template variants
        // (showcase/classic/minimal/luxe/sticky) — a superset of showcase.
        return \Inertia\Inertia::render('Product', [
            'pageTitle' => $product->meta_title ?: $product->name,
        ] + \App\Support\Storefront\ProductPageData::make(
            $product, $related, $crossSells, $pp, $lovesCount, $loved, $vcEventId, $recentlyViewed,
        ))->withViewData([
            'pageTitle' => $product->meta_title ?: $product->name,
            'product' => $product,
            // The main product photo is the LCP element here.
            'preloadImage' => $mainImage = $product->images->first()?->url,
            'preloadSrcset' => ($mainImage && ($v = image_variant($mainImage)))
                ? $v.' 450w, '.$mainImage.' 1200w' : null,
            'preloadSizes' => '(min-width: 1024px) 50vw, 100vw',
        ]);
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
        return $this->tracking->customerMatchData(
            $request->user('customer') ?? auth('customer')->user(),
        );
    }

    /**
     * Products most often purchased in the same orders as $product.
     * Falls back to the admin's manual cross-sells, then same-category items.
     */
    /**
     * Top a recommendation row up to $limit with genuinely relevant products,
     * so the row is never short (or empty) when the admin hasn't picked enough.
     *
     * Tiers, best first: same category → shares a tag → most viewed. Each tier
     * is ordered by a day-stable pseudo-random key, so the row looks freshly
     * mixed and rotates daily without reshuffling on every refresh.
     */
    protected function fillRelated(Product $product, \Illuminate\Support\Collection $picked, int $limit = 5): \Illuminate\Support\Collection
    {
        $picked = $picked->take($limit)->values();
        if ($picked->count() >= $limit) {
            return $picked;
        }

        $exclude = $picked->pluck('id')->push($product->id)->all();
        $tags = collect($product->tag_list ?? [])->filter()->take(5);
        $seed = now()->toDateString().'|'.$product->id;

        $tiers = [
            fn () => Product::published()->where('category_id', $product->category_id),
            fn () => $tags->isEmpty() ? null : Product::published()->where(function ($q) use ($tags) {
                foreach ($tags as $t) {
                    $q->orWhere('tags', 'like', '%'.$t.'%');
                }
            }),
            fn () => Product::published()->orderByDesc('views'),
        ];

        foreach ($tiers as $makeQuery) {
            if ($picked->count() >= $limit) {
                break;
            }
            $query = $makeQuery();
            if (! $query) {
                continue;
            }

            $pool = $query->whereNotIn('id', $exclude)
                ->with('images', 'approvedReviews', 'category')
                ->take(24)->get()
                ->sortBy(fn ($p) => crc32($seed.'|'.$p->id))
                ->take($limit - $picked->count())
                ->values();

            $picked = $picked->concat($pool);
            $exclude = array_merge($exclude, $pool->pluck('id')->all());
        }

        return $picked->values();
    }

    /**
     * Companions for the bundle. The block shows this product plus these, so
     * the default of 3 keeps the whole "frequently bought together" row at 4
     * items — past that the checkboxes stop reading as one buyable bundle.
     */
    protected function frequentlyBoughtTogether(Product $product, int $limit = 3): \Illuminate\Support\Collection
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

        // The admin's manual cross-sells lead (they're a deliberate choice),
        // then real co-purchase pairs, then relevance fill — always $limit items.
        $picked = $product->crossSells();

        if ($ids->isNotEmpty()) {
            $chosen = $picked->pluck('id')->all();
            $coPurchased = Product::published()->whereIn('id', $ids)->whereNotIn('id', $chosen)
                ->with('images', 'approvedReviews', 'category')->get()
                ->sortBy(fn ($p) => $ids->search($p->id))->values();
            $picked = $picked->concat($coPurchased);
        }

        return $this->fillRelated($product, $picked, $limit);
    }
}
