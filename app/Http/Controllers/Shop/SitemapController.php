<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * XML sitemap: home, catalog pages, active categories, published products and
 * the static pages. Cached for an hour (busted implicitly by TTL — product
 * churn doesn't need instant sitemap freshness).
 */
class SitemapController extends Controller
{
    public function index()
    {
        $xml = Cache::remember('sitemap.xml.v1', 3600, function () {
            $urls = [];

            $add = function (string $loc, ?string $lastmod = null, string $freq = 'weekly', string $priority = '0.6') use (&$urls) {
                $urls[] = ['loc' => $loc, 'lastmod' => $lastmod, 'freq' => $freq, 'priority' => $priority];
            };

            // Core pages.
            $add(route('home'), null, 'daily', '1.0');
            $add(route('shop'), null, 'daily', '0.9');
            $add(route('best-sellers'), null, 'daily', '0.8');
            $add(route('discover'), null, 'weekly', '0.6');
            $add(route('page.contact'), null, 'monthly', '0.3');
            $add(route('page.privacy'), null, 'yearly', '0.2');
            $add(route('page.terms'), null, 'yearly', '0.2');
            $add(route('page.refund'), null, 'yearly', '0.2');

            Category::active()->get(['slug', 'updated_at'])->each(function ($c) use ($add) {
                $add(route('category.show', $c->slug), $c->updated_at?->toAtomString(), 'weekly', '0.7');
            });

            Product::published()->get(['slug', 'updated_at'])->each(function ($p) use ($add) {
                $add(route('product.show', $p->slug), $p->updated_at?->toAtomString(), 'weekly', '0.8');
            });

            $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            foreach ($urls as $u) {
                $out .= '  <url><loc>'.e($u['loc']).'</loc>'
                    .($u['lastmod'] ? '<lastmod>'.$u['lastmod'].'</lastmod>' : '')
                    .'<changefreq>'.$u['freq'].'</changefreq>'
                    .'<priority>'.$u['priority'].'</priority></url>'."\n";
            }
            $out .= '</urlset>';

            return $out;
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
