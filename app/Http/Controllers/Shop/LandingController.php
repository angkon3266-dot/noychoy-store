<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\Product;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function show(Request $request, string $slug)
    {
        $page = LandingPage::where('slug', $slug)->firstOrFail();

        // Drafts stay private, but a logged-in admin can preview them.
        if (! $page->is_published && ! auth('web')->check()) {
            abort(404);
        }

        // Count views without touching updated_at (keeps caches/sorting stable).
        LandingPage::whereKey($page->id)->increment('views');

        $attached = $page->products();

        // Hydrate blocks: product sources for carousels/buy boxes, reviews, videos.
        $blocks = collect($page->blocks ?? [])
            ->filter(fn ($b) => ($b['enabled'] ?? true) && filled($b['type'] ?? null))
            ->map(function ($b) use ($attached) {
                if (in_array($b['type'], ['product_carousel', 'banner_carousel'], true)) {
                    $b['products'] = $this->sourceProducts($b, $attached);
                }
                if ($b['type'] === 'buy_box') {
                    // The buy box always sells this page's attached products.
                    $b['products'] = $attached;
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

        return view('shop.landing', compact('page', 'blocks', 'attached'))->with([
            // Handed to partials.seo-head rather than emitted by the view, so a
            // landing page gets the same canonical/robots/JSON-LD treatment as
            // the rest of the site instead of its own half-set of OG tags.
            'pageTitle' => $page->meta_title ?: $page->title,
            'metaDescription' => $page->meta_description,
            'ogImage' => theme_asset($page->og_image),
            // A draft is only reachable by a logged-in admin, but if one shares
            // the preview link it must not end up in the index.
            'metaRobots' => $page->is_published ? null : 'noindex, nofollow',
        ]);
    }

    /** Products for a carousel block: this page's picks, or a catalog source. */
    protected function sourceProducts(array $block, \Illuminate\Support\Collection $attached): \Illuminate\Support\Collection
    {
        $limit = max(1, (int) ($block['limit'] ?? 10));

        return match ($block['source'] ?? 'attached') {
            'attached' => $attached->take($limit),
            'best' => Product::published()->bestsellers()->latest()->take($limit)->with('images', 'approvedReviews', 'category')->get(),
            'featured' => Product::published()->featured()->latest()->take($limit)->with('images', 'approvedReviews', 'category')->get(),
            'category' => Product::published()->where('category_id', $block['category_id'] ?? 0)
                ->latest()->take($limit)->with('images', 'approvedReviews', 'category')->get(),
            default => Product::published()->latest()->take($limit)->with('images', 'approvedReviews', 'category')->get(),
        };
    }
}
