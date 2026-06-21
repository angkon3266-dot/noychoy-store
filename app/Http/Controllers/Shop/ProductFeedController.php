<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Meta (Facebook/Instagram) product catalog feed — CSV.
 * Paste the URL into Meta Commerce Manager → Catalog → Data sources → Scheduled feed.
 * Use product_type / custom_label_N to build product sets per category for ads.
 */
class ProductFeedController extends Controller
{
    public function meta(Request $request): StreamedResponse
    {
        $brand = config('store.name', 'Noychoy');
        $currency = config('store.currency', 'BDT');

        $columns = [
            'id', 'title', 'description', 'availability', 'condition',
            'price', 'sale_price', 'link', 'image_link', 'additional_image_link',
            'brand', 'product_type', 'custom_label_0', 'custom_label_1', 'google_product_category',
        ];

        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'inline; filename="meta-catalog.csv"',
        ];

        return response()->stream(function () use ($columns, $brand, $currency) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            Product::published()
                ->with(['images', 'category', 'categories'])
                ->when(request('category'), function ($q, $slug) {
                    $q->whereHas('categories', fn ($c) => $c->where('slug', $slug))
                      ->orWhereHas('category', fn ($c) => $c->where('slug', $slug));
                })
                ->chunk(200, function ($products) use ($out, $brand, $currency) {
                    foreach ($products as $p) {
                        $images = $p->images;
                        $primary = $images->firstWhere('is_primary', true) ?? $images->first();
                        if (! $primary) {
                            continue; // Meta requires an image_link
                        }

                        $available = $p->isAvailable() || $p->isPreorder();
                        $cats = $p->categories->pluck('name');
                        $additional = $images->where('id', '!=', $primary->id)->take(10)
                            ->map(fn ($i) => $this->absUrl($i->url))->implode(',');

                        fputcsv($out, [
                            $p->id,
                            $p->name,
                            \Illuminate\Support\Str::limit(strip_tags($p->description ?: $p->short_description ?: $p->name), 4900, ''),
                            $available ? 'in stock' : 'out of stock',
                            'new',
                            number_format((float) ($p->compare_at_price ?: $p->price), 2, '.', '').' '.$currency,
                            $p->is_on_sale ? number_format((float) $p->price, 2, '.', '').' '.$currency : '',
                            route('product.show', $p),
                            $this->absUrl($primary->url),
                            $additional,
                            $brand,
                            $cats->implode(' > '),       // product_type (your taxonomy)
                            $cats->get(0) ?? '',          // custom_label_0 → product set per category
                            $cats->get(1) ?? '',          // custom_label_1
                            '',                            // google_product_category (optional)
                        ]);
                    }
                });

            fclose($out);
        }, 200, $headers);
    }

    /** Make a stored relative image path into an absolute URL. */
    protected function absUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim(config('app.url'), '/').'/'.ltrim($url, '/');
    }
}
