<?php

namespace App\Support\Storefront;

use App\Support\DailyDeals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Serializes everything the React home page (the "couture" template + the
 * section-builder blocks) renders. Mirrors the inline @php blocks the Blade
 * templates used to run.
 */
class HomePageData
{
    public static function make(
        Collection $featured,
        Collection $newArrivals,
        Collection $bestSellers,
        Collection $categories,
        Collection $sections,
    ): array {
        return [
            'pageTitle' => home_content('seo_title') ?: 'Fine Jewelry',
            'hero' => [
                'eyebrow' => home_content('hero_eyebrow') ?: 'Handcrafted in Bangladesh',
                'headingHtml' => home_content_heading('text-gold-700'),
                'subtitle' => home_content('hero_subtitle') ?: 'Timeless pieces, made to be worn every day and remembered forever.',
                'ctaText' => home_content('hero_cta_text') ?: 'Shop the collection',
                'ctaLink' => home_content('hero_cta_link') ?: route('shop'),
                'secondaryText' => home_content('hero_secondary_text'),
                'secondaryLink' => home_content('hero_secondary_link') ?: route('track'),
                'slides' => self::heroSlides($featured),
            ],
            'deals' => self::deals(),
            'categoriesSection' => [
                'show' => (bool) home_content('show_categories'),
                'title' => home_content('categories_title') ?: 'Shop by category',
                'items' => $categories->map(function ($c) {
                    // These used to hand the browser the full 1600px original
                    // for a tile a few hundred pixels wide.
                    $full = $c->image
                        ? (Str::startsWith($c->image, 'http') ? $c->image : Storage::disk('public')->url($c->image))
                        : null;

                    return [
                        'name' => $c->name,
                        'url' => route('category.show', $c),
                        'image' => image_variant($full, 900) ?: $full,
                        'srcset' => image_srcset($full),
                    ];
                })->values(),
            ],
            'featured' => [
                'title' => home_content('featured_title') ?: 'The Signature Edit',
                'cards' => ProductCardData::collection($featured),
            ],
            'promise' => [
                'show' => (bool) home_content('show_promise'),
                'eyebrow' => home_content('promise_eyebrow') ?: 'Our promise',
                'title' => home_content('promise_title') ?: 'Crafted to be treasured',
                'text' => home_content('promise_text'),
                'image' => ($promiseImg = theme_asset(home_content('promise_image')) ?: $newArrivals->first()?->thumbnail),
                'imageSrcset' => image_srcset($promiseImg),
                'badges' => collect(range(1, 3))->map(fn ($b) => [
                    'title' => home_content('badge'.$b.'_title') ?: ['COD', 'Fast', 'Quality'][$b - 1],
                    'text' => home_content('badge'.$b.'_text') ?: ['Pay on delivery', 'Nationwide', 'Hand-finished'][$b - 1],
                ])->values(),
            ],
            'newArrivals' => [
                'show' => (bool) home_content('show_new_arrivals'),
                'title' => home_content('new_arrivals_title') ?: 'New Arrivals',
                'cards' => ProductCardData::collection($newArrivals),
            ],
            'bestSellers' => [
                'show' => (bool) home_content('show_best_selling'),
                'title' => home_content('best_selling_title') ?: 'Best sellers',
                'cards' => ProductCardData::collection($bestSellers->take(8)),
            ],
            'featureStrip' => [
                'show' => (bool) home_content('show_feature_strip'),
                'items' => collect(home_content('feature_strip') ?? [])
                    ->filter(fn ($f) => filled($f['title'] ?? null))->take(4)->values()
                    ->map(fn ($f) => ['icon' => $f['icon'] ?? null, 'title' => $f['title'], 'text' => $f['text'] ?? null]),
            ],
            // Gift-led entry points. Unlike the old Blade template, a tile
            // renders with or without a photo (typographic tile when none) so
            // the gifting path exists from day one.
            'occasions' => [
                'show' => (bool) home_content('show_occasions'),
                'title' => home_content('occasions_title') ?: 'Shop by occasion',
                'subtitle' => home_content('occasions_subtitle'),
                'items' => collect(home_content('occasions') ?? [])
                    ->filter(fn ($o) => filled($o['label'] ?? null))->take(8)->values()
                    ->map(function ($o) {
                        $image = theme_asset($o['image'] ?? null);

                        return [
                            'label' => $o['label'],
                            'tagline' => $o['tagline'] ?? null,
                            'link' => filled($o['link'] ?? null) ? $o['link'] : route('shop'),
                            'image' => $image,
                            'image450' => $image ? image_variant($image, 450) : null,
                        ];
                    }),
            ],
            'giftFinder' => [
                'show' => (bool) home_content('show_gift_finder'),
                'title' => home_content('gift_finder_title') ?: 'Shopping for someone?',
                'budgets' => collect(home_content('gift_budgets') ?? [])->map(function ($b) {
                    $min = $b['min'] ?? null;
                    $max = $b['max'] ?? null;

                    return [
                        'label' => $max === null ? money($min).'+' : ($min === null ? 'Under '.money($max) : money($min).' – '.money($max)),
                        'url' => route('shop', array_filter(['price_min' => $min, 'price_max' => $max])),
                    ];
                })->values(),
                'text' => home_content('gift_finder_text'),
                'promises' => collect(home_content('gift_promises') ?? [])
                    ->filter(fn ($p) => filled($p['title'] ?? null))->take(3)->values()
                    ->map(fn ($p) => ['icon' => $p['icon'] ?? null, 'title' => $p['title'], 'text' => $p['text'] ?? null]),
                'steps' => collect(home_content('gifting_steps') ?? [])
                    ->filter(fn ($p) => filled($p['title'] ?? null))->take(4)->values()
                    ->map(fn ($p) => ['title' => $p['title'], 'text' => $p['text'] ?? null]),
            ],
            'heroTrust' => collect(home_content('hero_trust') ?? [])->filter(fn ($t) => filled($t))->take(3)->values(),
            // Social proof: recent 4–5★ approved reviews, sitewide. Cached like
            // the deals block — it ran two uncached queries on every homepage
            // render for content that changes a few times a week at most.
            'reviews' => \Illuminate\Support\Facades\Cache::remember('home.reviews.band', 600, fn () => \App\Models\Review::approved()->with('product:id,name,slug')
                ->where('rating', '>=', 4)->where(fn ($q) => $q->whereNotNull('body')->orWhereNotNull('title'))
                ->latest()->take(6)->get()
                ->map(fn ($r) => [
                    'author' => $r->author_name,
                    'rating' => (int) $r->rating,
                    'quote' => $r->body ?: $r->title,
                    'product' => $r->product ? ['name' => $r->product->name, 'url' => route('product.show', $r->product->slug)] : null,
                ])->values()),
            'blocks' => $sections->map(fn ($b) => self::block($b))->filter()->values(),
        ];
    }

    /** Same slide-building contract as the couture/meridian Blade templates. */
    protected static function heroSlides(Collection $featured): array
    {
        $slides = collect(home_content('hero_slides') ?? [])
            ->take(8)
            ->map(function ($s) {
                $video = filled($s['video'] ?? null) ? video_meta($s['video']) : null;
                $image = $video ? null : theme_asset($s['image'] ?? null);
                if (! $video && ! $image) {
                    return null;
                }

                return [
                    'type' => $video ? 'video' : 'image',
                    'video' => $video ? self::heroVideo($video) : null,
                    'image' => $image ?: ($video['thumb'] ?? null),
                    'image900' => $image ? image_variant($image, 900) : null,
                    'link' => filled($s['link'] ?? null) ? $s['link'] : null,
                    'alt' => $s['alt'] ?? '',
                ];
            })
            ->filter()->values();

        if ($slides->isEmpty()) {
            if ($heroImg = theme_asset(home_content('hero_image'))) {
                $slides->push([
                    'type' => 'image', 'video' => null, 'image' => $heroImg,
                    'image900' => image_variant($heroImg, 900),
                    'link' => home_content('hero_cta_link') ?: route('shop'), 'alt' => '',
                ]);
            }
            foreach ($featured->take(5) as $p) {
                if ($p->thumbnail) {
                    $slides->push([
                        'type' => 'image', 'video' => null, 'image' => $p->thumbnail,
                        'image900' => image_variant($p->thumbnail, 900),
                        'link' => route('product.show', $p), 'alt' => $p->name,
                    ]);
                }
            }
        }

        return $slides->values()->all();
    }

    /** Ambient autoplay parameters precomputed, as the Blade did inline. */
    protected static function heroVideo(array $video): array
    {
        if ($video['type'] === 'file') {
            return ['type' => 'file', 'src' => $video['src']];
        }

        $embed = $video['embed'];
        $embed .= $video['type'] === 'youtube'
            ? (str_contains($embed, '?') ? '&' : '?').'autoplay=1&mute=1&loop=1&playlist='.Str::afterLast($embed, '/').'&controls=0&modestbranding=1&rel=0'
            : (str_contains($embed, '?') ? '&' : '?').'autoplay=1&muted=1&loop=1&background=1';

        return ['type' => $video['type'], 'embedUrl' => $embed];
    }

    protected static function deals(): ?array
    {
        $cards = DailyDeals::cards();
        if ($cards->isEmpty()) {
            return null;
        }

        return [
            'title' => home_content('deals_title') ?: 'Deals of the Day',
            'subtitle' => home_content('deals_subtitle'),
            'endsAt' => DailyDeals::endsAt()?->getTimestamp(),
            'cards' => $cards->values(),
        ];
    }

    /** One serialized section-builder block; null drops it from the payload. */
    protected static function block(array $b): ?array
    {
        $img = function ($p) {
            $p = (string) ($p ?? '');

            return $p === '' ? null : (Str::startsWith($p, ['http', '/']) ? $p : Storage::disk('public')->url($p));
        };

        $type = $b['type'] ?? 'banner';
        $out = ['type' => $type, 'title' => $b['title'] ?? ''];

        switch ($type) {
            case 'banner':
                $out['layout'] = $b['layout'] ?? 'single';
                $out['images'] = collect($b['images'] ?? [])
                    ->filter(fn ($i) => filled($i['image'] ?? null))
                    ->map(fn ($i) => ['image' => $img($i['image']), 'link' => $i['link'] ?? null])
                    ->values();

                return $out['images']->isNotEmpty() ? $out : null;

            case 'product_carousel':
                $out['view_all_link'] = $b['view_all_link'] ?? route('shop');
                $out['cards'] = ProductCardData::collection($b['products'] ?? collect());

                return count($out['cards']) ? $out : null;

            case 'banner_carousel':
                $banner = $b['banner'] ?? [];
                $out['banner'] = filled($banner['image'] ?? null)
                    ? ['image' => $img($banner['image']), 'link' => $banner['link'] ?? null] : null;
                $out['cards'] = ProductCardData::collection($b['products'] ?? collect());

                return $out;

            case 'video':
                $out['videos'] = collect($b['videos'] ?? [])
                    ->map(fn ($v) => ['title' => $v['title'] ?? '', 'meta' => $v['meta'] ?? null])
                    ->filter(fn ($v) => $v['meta'] !== null)->values();

                return $out['videos']->isNotEmpty() ? $out : null;

            case 'cta_banner':
                $cta = $b['cta'] ?? [];
                $out['cta'] = [
                    'image' => $img($cta['image'] ?? null),
                    'eyebrow' => $cta['eyebrow'] ?? null,
                    'heading' => $cta['heading'] ?? null,
                    'subheading' => $cta['subheading'] ?? null,
                    'button_text' => $cta['button_text'] ?? null,
                    'button_link' => ($cta['button_link'] ?? null) ?: route('shop'),
                    'align' => in_array($cta['align'] ?? 'center', ['left', 'center', 'right'], true) ? $cta['align'] : 'center',
                    'height' => $cta['height'] ?? 'md',
                ];

                return ($out['cta']['image'] || filled($out['cta']['heading'])) ? $out : null;

            case 'reviews':
                $reviews = collect($b['reviews'] ?? []);
                // No real reviews picked → no section. Sample testimonials used to
                // fill this slot; invented praise on a jewelry site is a trust risk.
                if ($reviews->isEmpty()) {
                    return null;
                }
                $out['items'] = $reviews->map(fn ($r) => [
                        'author' => $r->author_name,
                        'meta' => $r->product?->name,
                        'link' => $r->product ? route('product.show', $r->product->slug) : null,
                        'rating' => (int) $r->rating,
                        'quote' => $r->body ?: $r->title,
                    ])->values()->all();

                return $out;

            case 'hero_cta':
                $h = $b['hero'] ?? [];
                $out['hero'] = [
                    'image' => $img($h['image'] ?? null),
                    'dark' => (bool) ($h['dark'] ?? true),
                    'eyebrow' => $h['eyebrow'] ?? null,
                    'heading' => $h['heading'] ?? '',
                    'subheading' => $h['subheading'] ?? null,
                    'cta_text' => $h['cta_text'] ?? null,
                    'cta_link' => ($h['cta_link'] ?? null) ?: '#buy',
                    'cta2_text' => $h['cta2_text'] ?? null,
                    'cta2_link' => ($h['cta2_link'] ?? null) ?: '#',
                    'note' => $h['note'] ?? null,
                ];

                return $out;

            case 'benefits':
                $out['items'] = collect($b['benefits'] ?? [])
                    ->filter(fn ($x) => filled($x['title'] ?? null))->values();

                return $out['items']->isNotEmpty() ? $out : null;

            case 'countdown':
                $c = $b['countdown'] ?? [];
                $endsAt = filled($c['ends_at'] ?? null) ? Carbon::parse($c['ends_at']) : null;
                if (! $endsAt || ! $endsAt->isFuture()) {
                    return null;
                }
                $out['countdown'] = [
                    'title' => $c['title'] ?? null,
                    'endsAt' => $endsAt->getTimestamp(),
                    'cta_text' => $c['cta_text'] ?? null,
                    'cta_link' => ($c['cta_link'] ?? null) ?: '#buy',
                ];

                return $out;

            case 'buy_box':
                $out['cta_label'] = $b['cta_label'] ?? 'Add to cart';
                $out['items'] = collect($b['products'] ?? collect())->map(fn ($p) => [
                    'name' => $p->name,
                    'url' => route('product.show', $p),
                    'thumb' => $p->thumbnail,
                    'price_text' => money($p->price),
                    'compare_text' => $p->compare_at_price > $p->price ? money($p->compare_at_price) : null,
                    'add_url' => route('cart.add', $p->slug),
                ])->values();

                return $out['items']->isNotEmpty() ? $out : null;

            case 'faq':
                $out['items'] = collect($b['faqs'] ?? [])
                    ->filter(fn ($f) => filled($f['q'] ?? null))->values();

                return $out['items']->isNotEmpty() ? $out : null;

            case 'sticky_cta':
                $s = $b['sticky'] ?? [];
                $out['sticky'] = ['text' => $s['text'] ?? '', 'button' => $s['button'] ?? 'Order now', 'link' => ($s['link'] ?? null) ?: '#buy'];

                return (filled($s['text'] ?? null) || filled($s['button'] ?? null)) ? $out : null;

            case 'richtext':
                $out['html'] = $b['html'] ?? '';

                return filled($out['html']) ? $out : null;
        }

        return null;
    }
}
