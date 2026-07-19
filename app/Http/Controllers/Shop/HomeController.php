<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /** Cache key for the homepage id-plan (bumped on product/category/appearance saves). */
    public const CACHE_KEY = 'home.plan.v1';

    public function index(Request $request)
    {
        // The expensive part of the homepage is DECIDING what to show (several
        // product queries incl. a SUM over order_items for auto-bestsellers).
        // We cache that decision as plain ids — the app's cache store refuses to
        // serialize objects (cache.serializable_classes = false) — then hydrate
        // everything with one indexed whereIn query, so prices/stock stay live.
        $plan = \Illuminate\Support\Facades\Cache::remember(self::CACHE_KEY, 600, function () {
            $sectionBlocks = collect(home_content('sections') ?? [])
                ->filter(fn ($b) => ($b['enabled'] ?? true) && filled($b['type'] ?? null))
                ->map(function ($b) {
                    if (in_array($b['type'], ['product_carousel', 'banner_carousel'], true)) {
                        $b['product_ids'] = $this->sourceProductIds($b['source'] ?? 'new', $b['category_id'] ?? null, (int) ($b['limit'] ?? 10));
                    }

                    return $b;
                })->values()->all();

            return [
                'featured' => Product::published()->featured()->latest()->take(8)->pluck('id')->all(),
                'new' => Product::published()->latest()->take(8)->pluck('id')->all(),
                'best' => $this->bestSellerIds(8),
                'sections' => $sectionBlocks,
            ];
        });

        // One query hydrates every product used anywhere on the page.
        $with = ['images', 'approvedReviews', 'category'];
        $allIds = collect([$plan['featured'], $plan['new'], $plan['best']])
            ->flatten()
            ->merge(collect($plan['sections'])->pluck('product_ids')->flatten()->filter())
            ->unique()->values();
        $pool = Product::published()->with($with)->whereIn('id', $allIds)->get()->keyBy('id');
        $pick = fn (array $ids) => collect($ids)->map(fn ($id) => $pool->get($id))->filter()->values();

        $featured = $pick($plan['featured']);
        $newArrivals = $pick($plan['new']);
        $bestSellers = $pick($plan['best']);

        // Category scroller — admin-chosen categories in order, else auto (top parents).
        $scrollerIds = collect(home_content('category_scroller_ids') ?? [])->map(fn ($i) => (int) $i)->filter();
        $categories = $scrollerIds->isNotEmpty()
            ? Category::active()->whereIn('id', $scrollerIds)->get()
                ->sortBy(fn ($c) => $scrollerIds->search($c->id))->values()
            : Category::active()->whereNull('parent_id')->orderBy('position')->take(10)->get();

        // Highlighted categories (large editorial tiles), in the admin-chosen order.
        $highlightIds = collect(home_content('highlight_category_ids') ?? [])->map(fn ($i) => (int) $i)->filter();
        $highlightCategories = $highlightIds->isEmpty() ? collect() : Category::whereIn('id', $highlightIds)->get()
            ->sortBy(fn ($c) => $highlightIds->search($c->id))->values();

        // Custom section blocks: hydrate products + video meta from the plan.
        $sections = collect($plan['sections'])->map(function ($b) use ($pick) {
            if (isset($b['product_ids'])) {
                $b['products'] = $pick($b['product_ids']);
            }
            if ($b['type'] === 'reviews') {
                $ids = collect($b['review_ids'] ?? [])->map(fn ($i) => (int) $i)->filter()->values();
                $b['reviews'] = $ids->isEmpty() ? collect() : \App\Models\Review::approved()
                    ->with('product:id,name,slug')->whereIn('id', $ids)->get()
                    ->sortBy(fn ($r) => $ids->search($r->id))->values();
            }
            if ($b['type'] === 'video') {
                $b['videos'] = collect($b['videos'] ?? [])
                    ->map(fn ($v) => ['title' => $v['title'] ?? '', 'meta' => video_meta($v['url'] ?? '')])
                    ->filter(fn ($v) => $v['meta'] !== null)->values();
            }

            return $b;
        })->values();

        // Logged-in admins can preview any template via ?preview_home=KEY without saving it.
        $key = theme('homepage_template');
        if ($request->filled('preview_home') && auth('web')->check()
            && config('theme.homepage_templates.'.$request->query('preview_home'))) {
            $key = $request->query('preview_home');
        }

        $view = config('theme.homepage_templates.'.$key.'.view');
        if (! $view || ! view()->exists($view)) {
            $view = 'shop.templates.home.storefront';
        }

        return view($view, compact(
            'featured', 'newArrivals', 'bestSellers', 'categories', 'highlightCategories', 'sections'
        ));
    }

    /** Resolve a builder block's product source keyword to an ordered id list. */
    protected function sourceProductIds(string $source, $categoryId, int $limit): array
    {
        $limit = max(1, min(20, $limit));

        return match ($source) {
            'best' => $this->bestSellerIds($limit),
            'featured' => Product::published()->featured()->latest()->take($limit)->pluck('id')->all(),
            'category' => $categoryId
                ? Product::published()
                    ->where(fn ($w) => $w->where('category_id', $categoryId)
                        ->orWhereHas('categories', fn ($c) => $c->where('categories.id', $categoryId)))
                    ->latest()->take($limit)->pluck('id')->all()
                : [],
            default => Product::published()->latest()->take($limit)->pluck('id')->all(), // new
        };
    }

    /**
     * Best sellers: admin-flagged products first, then the top auto-sellers
     * (by units sold), finally newest published products to fill the row.
     */
    protected function bestSellerIds(int $limit): array
    {
        $best = Product::published()->bestsellers()->latest()->take($limit)->pluck('id');

        if ($best->count() < $limit) {
            $topIds = OrderItem::query()
                ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'returned']))
                ->whereNotNull('product_id')
                ->select('product_id', DB::raw('SUM(quantity) as q'))
                ->groupBy('product_id')->orderByDesc('q')
                ->pluck('product_id')
                ->reject(fn ($id) => $best->contains($id))
                ->take($limit - $best->count());

            if ($topIds->isNotEmpty()) {
                // Keep only ids that are still published, preserving sales order.
                $published = Product::published()->whereIn('id', $topIds)->pluck('id');
                $best = $best->concat($topIds->filter(fn ($id) => $published->contains($id)));
            }
        }

        if ($best->count() < $limit) {
            $fill = Product::published()->whereNotIn('id', $best)->latest()
                ->take($limit - $best->count())->pluck('id');
            $best = $best->concat($fill);
        }

        return $best->take($limit)->map(fn ($id) => (int) $id)->values()->all();
    }
}
