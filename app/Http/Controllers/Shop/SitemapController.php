<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * XML sitemap: home, catalog pages, active categories and collections,
 * published products, admin-built landing pages and the static pages.
 * Cached for an hour (busted implicitly by TTL — product churn doesn't need
 * instant sitemap freshness).
 *
 * Product entries carry image:image children. That is not decoration for a
 * jewelry shop: photography is the product, and Google Images is a large slice
 * of how Bangladeshi shoppers on phones find things to buy. An image Google
 * never crawls cannot rank, and the images here are only reachable through a
 * React-rendered gallery.
 */
class SitemapController extends Controller
{
    public function index()
    {
        // System Config → SEO → "Sitemap enabled" used to be settable and then
        // ignored, so turning it off left the sitemap happily serving.
        abort_unless(config('seo.sitemap_enabled', true), 404);

        $xml = Cache::remember('sitemap.xml.v3', 3600, function () {
            $urls = [];

            $add = function (
                string $loc,
                ?string $lastmod = null,
                string $freq = 'weekly',
                string $priority = '0.6',
                array $images = [],
            ) use (&$urls) {
                $urls[] = compact('loc', 'lastmod', 'freq', 'priority', 'images');
            };

            // Core pages. The homepage's lastmod is the newest published
            // product — the honest answer to "has anything changed here?".
            $add(route('home'), Product::published()->max('updated_at'), 'daily', '1.0');
            $add(route('shop'), null, 'daily', '0.9');
            $add(route('best-sellers'), null, 'daily', '0.8');
            $add(route('discover'), null, 'weekly', '0.6');
            $add(route('page.about'), null, 'monthly', '0.4');
            $add(route('page.contact'), null, 'monthly', '0.3');
            $add(route('page.privacy'), null, 'yearly', '0.2');
            $add(route('page.terms'), null, 'yearly', '0.2');
            $add(route('page.refund'), null, 'yearly', '0.2');

            Category::active()->get(['slug', 'updated_at'])->each(function ($c) use ($add) {
                $add(route('category.show', $c->slug), $c->updated_at?->toAtomString(), 'weekly', '0.7');
            });

            Collection::active()->get(['slug', 'updated_at'])->each(function ($c) use ($add) {
                $add(route('collection.show', $c->slug), $c->updated_at?->toAtomString(), 'weekly', '0.7');
            });

            Product::published()->with(['images' => fn ($q) => $q->orderBy('position')])
                ->get(['id', 'name', 'slug', 'updated_at'])
                ->each(function ($p) use ($add) {
                    $add(
                        route('product.show', $p->slug),
                        $p->updated_at?->toAtomString(),
                        'weekly',
                        '0.8',
                        $p->images->take(5)->map(fn ($i) => [
                            'loc' => $i->url,
                            'title' => $i->alt ?: $p->name,
                        ])->all(),
                    );
                });

            // Marketing landing pages: real, indexable URLs that nothing else
            // in the sitemap pointed at, so they were invisible to search.
            foreach ($this->landingPages() as $page) {
                $add($page['loc'], $page['lastmod'], 'monthly', '0.5');
            }

            return $this->render($urls);
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * @param  list<array{loc:string,lastmod:?string,freq:string,priority:string,images:list<array{loc:string,title:string}>}>  $urls
     */
    protected function render(array $urls): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            .' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

        foreach ($urls as $u) {
            $out .= '  <url><loc>'.e($u['loc']).'</loc>'
                .($u['lastmod'] ? '<lastmod>'.e($u['lastmod']).'</lastmod>' : '')
                .'<changefreq>'.$u['freq'].'</changefreq>'
                .'<priority>'.$u['priority'].'</priority>';

            foreach ($u['images'] as $image) {
                $out .= '<image:image><image:loc>'.e($image['loc']).'</image:loc>'
                    .'<image:title>'.e($image['title']).'</image:title></image:image>';
            }

            $out .= '</url>'."\n";
        }

        return $out.'</urlset>';
    }

    /**
     * Published landing pages, if the table exists. Guarded rather than
     * assumed: the sitemap must not 500 on an install that has never built one.
     *
     * @return list<array{loc:string,lastmod:?string}>
     */
    protected function landingPages(): array
    {
        if (! class_exists(\App\Models\LandingPage::class)) {
            return [];
        }

        try {
            return \App\Models\LandingPage::query()
                ->where('is_published', true)
                ->get(['slug', 'updated_at'])
                ->map(fn ($p) => [
                    'loc' => route('landing.show', $p->slug),
                    'lastmod' => $p->updated_at?->toAtomString(),
                ])->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
