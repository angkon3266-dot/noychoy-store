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
    public function index(Request $request)
    {
        $with = ['images', 'approvedReviews', 'category'];

        $featured = Product::published()->featured()->with($with)->latest()->take(8)->get();
        $newArrivals = Product::published()->with($with)->latest()->take(8)->get();
        $bestSellers = $this->bestSellers($with, 8);

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

        // Homepage video sections (YouTube / Vimeo / uploaded MP4).
        $homeVideos = collect(home_content('videos') ?? [])
            ->map(fn ($v) => ['title' => $v['title'] ?? '', 'meta' => video_meta($v['url'] ?? '')])
            ->filter(fn ($v) => $v['meta'] !== null)->values();

        // Custom section blocks (page builder). Empty = fall back to fixed sections.
        $sections = $this->resolveSections(home_content('sections') ?? [], $with);

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
            'featured', 'newArrivals', 'bestSellers', 'categories', 'highlightCategories', 'homeVideos', 'sections'
        ));
    }

    /** Turn stored builder blocks into render-ready blocks (with products resolved). */
    protected function resolveSections(array $blocks, array $with): \Illuminate\Support\Collection
    {
        return collect($blocks)
            ->filter(fn ($b) => ($b['enabled'] ?? true) && filled($b['type'] ?? null))
            ->map(function ($b) use ($with) {
                $type = $b['type'];
                if (in_array($type, ['product_carousel', 'banner_carousel'], true)) {
                    $b['products'] = $this->sourceProducts($b['source'] ?? 'new', $b['category_id'] ?? null, (int) ($b['limit'] ?? 10), $with);
                }
                if ($type === 'video') {
                    $b['videos'] = collect($b['videos'] ?? [])
                        ->map(fn ($v) => ['title' => $v['title'] ?? '', 'meta' => video_meta($v['url'] ?? '')])
                        ->filter(fn ($v) => $v['meta'] !== null)->values();
                }
                return $b;
            })->values();
    }

    /** Resolve a product source keyword to a collection. */
    protected function sourceProducts(string $source, $categoryId, int $limit, array $with): \Illuminate\Support\Collection
    {
        $limit = max(1, min(20, $limit));

        return match ($source) {
            'best' => $this->bestSellers($with, $limit),
            'featured' => Product::published()->featured()->with($with)->latest()->take($limit)->get(),
            'category' => $categoryId
                ? Product::published()->with($with)
                    ->where(fn ($w) => $w->where('category_id', $categoryId)
                        ->orWhereHas('categories', fn ($c) => $c->where('categories.id', $categoryId)))
                    ->latest()->take($limit)->get()
                : collect(),
            default => Product::published()->with($with)->latest()->take($limit)->get(), // new
        };
    }

    /**
     * Best sellers: admin-flagged products first, then the top auto-sellers
     * (by units sold), finally newest published products to fill the row.
     */
    protected function bestSellers(array $with, int $limit): \Illuminate\Support\Collection
    {
        $best = Product::published()->bestsellers()->with($with)->latest()->take($limit)->get();

        if ($best->count() < $limit) {
            $topIds = OrderItem::query()
                ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'returned']))
                ->whereNotNull('product_id')
                ->select('product_id', DB::raw('SUM(quantity) as q'))
                ->groupBy('product_id')->orderByDesc('q')
                ->pluck('product_id')
                ->reject(fn ($id) => $best->contains('id', $id))
                ->take($limit - $best->count());

            if ($topIds->isNotEmpty()) {
                $auto = Product::published()->whereIn('id', $topIds)->with($with)->get()
                    ->sortBy(fn ($p) => $topIds->search($p->id));
                $best = $best->concat($auto);
            }
        }

        if ($best->count() < $limit) {
            $have = $best->pluck('id');
            $fill = Product::published()->whereNotIn('id', $have)->with($with)->latest()
                ->take($limit - $best->count())->get();
            $best = $best->concat($fill);
        }

        return $best->take($limit)->values();
    }
}
