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
    /**
     * How many `video[N].url` columns the CSV carries. Meta allows 20, but the
     * header is fixed for every row, so this stays at the handful a product
     * actually uses rather than padding 20 empty columns onto the whole feed.
     */
    private const MAX_VIDEOS = 3;

    public function meta(Request $request): StreamedResponse
    {
        // Brand follows the store's own name — never a hardcoded one, so this
        // codebase stays correct for whatever store it's deployed for.
        $brand = config('meta.defaults.brand') ?: store_name();
        $currency = config('store.currency', 'BDT');

        $columns = array_merge([
            'id', 'item_group_id', 'title', 'description', 'availability', 'condition',
            'price', 'sale_price', 'link', 'image_link', 'additional_image_link',
            'brand', 'product_type', 'custom_label_0', 'custom_label_1', 'google_product_category',
        ], array_map(fn ($i) => "video[{$i}].url", range(0, self::MAX_VIDEOS - 1)));

        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'inline; filename="meta-catalog.csv"',
        ];

        return response()->stream(function () use ($columns, $brand, $currency) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            Product::published()
                ->with(['images', 'category', 'categories', 'variants'])
                // The closure is load-bearing: without it the orWhereHas escapes
                // published(), and ?category=x compiled to
                // "(published AND pivot-match) OR primary-match" — which fed
                // DRAFT products to Meta.
                ->when(request('category'), function ($q, $slug) {
                    $q->where(fn ($w) => $w->whereHas('categories', fn ($c) => $c->where('slug', $slug))
                        ->orWhereHas('category', fn ($c) => $c->where('slug', $slug)));
                })
                ->chunk(200, function ($products) use ($out, $brand, $currency) {
                    foreach ($products as $p) {
                        $images = $p->images;
                        $primary = $images->firstWhere('is_primary', true) ?? $images->first();
                        if (! $primary) {
                            continue; // Meta requires an image_link
                        }

                        $cats = $p->categories->pluck('name');
                        $additional = $images->where('id', '!=', $primary->id)->take(10)
                            ->map(fn ($i) => $this->absUrl($i->url))->implode(',');
                        $videos = $this->videoColumns($p);

                        $row = fn (array $over = []) => fputcsv($out, array_values(array_replace(array_merge([
                            // id MUST equal the content_id the Pixel/CAPI sends
                            // (meta_content_id → "prod-{id}"), or Meta counts
                            // every view and purchase as unmatched and the
                            // catalogue match rate sits at 0%.
                            'id' => meta_content_id($p),
                            'item_group_id' => '',
                            'title' => $p->name,
                            'description' => \Illuminate\Support\Str::limit(strip_tags($p->description ?: $p->short_description ?: $p->name), 4900, ''),
                            'availability' => ($p->isAvailable() || $p->isPreorder()) ? 'in stock' : 'out of stock',
                            'condition' => 'new',
                            'price' => number_format((float) ($p->compare_at_price ?: $p->price), 2, '.', '').' '.$currency,
                            'sale_price' => $p->is_on_sale ? number_format((float) $p->price, 2, '.', '').' '.$currency : '',
                            'link' => route('product.show', $p),
                            'image_link' => $this->absUrl($primary->url),
                            'additional_image_link' => $additional,
                            'brand' => $brand,
                            'product_type' => $cats->implode(' > '),   // your taxonomy
                            'custom_label_0' => $cats->get(0) ?? '',   // product set per category
                            'custom_label_1' => $cats->get(1) ?? '',
                            'google_product_category' => $p->googleCategory() ?? '',
                            // Videos belong to the product, so every variant row
                            // repeats the parent's clips.
                        ], $videos), $over)));

                        // Variable products: one row per variant, matching the
                        // "prod-{id}-var-{vid}" ids that Purchase events send,
                        // grouped under the parent via item_group_id.
                        if ($p->has_variants && $p->variants->isNotEmpty()) {
                            foreach ($p->variants as $v) {
                                $price = $v->price !== null ? (float) $v->price : (float) $p->price;
                                $row([
                                    'id' => meta_content_id($p, $v),
                                    'item_group_id' => meta_content_id($p),
                                    'title' => trim($p->name.' '.$v->label),
                                    'availability' => ((int) $v->stock_quantity > 0 || $p->isPreorder()) ? 'in stock' : 'out of stock',
                                    'price' => number_format((float) ($p->compare_at_price ?: $price), 2, '.', '').' '.$currency,
                                    'sale_price' => $p->compare_at_price && $price < (float) $p->compare_at_price
                                        ? number_format($price, 2, '.', '').' '.$currency : '',
                                    'image_link' => $this->absUrl($v->image?->url ?: $primary->url),
                                ]);
                            }

                            continue;
                        }

                        $row();
                    }
                });

            fclose($out);
        }, 200, $headers);
    }

    /**
     * The `video[N].url` cells for one product, always MAX_VIDEOS wide so every
     * row lines up with the header.
     *
     * Meta downloads and re-hosts the file, so only a direct link to the video
     * itself works — a YouTube or Vimeo watch page is a player, not a file, and
     * is skipped rather than sent and rejected.
     *
     * @return array<string, string>
     */
    protected function videoColumns(Product $product): array
    {
        $urls = collect($product->galleryVideos())
            ->filter(fn ($v) => ($v['type'] ?? null) === 'file' && filled($v['src'] ?? null))
            ->map(fn ($v) => $this->absUrl($v['src']))
            ->take(self::MAX_VIDEOS)
            ->values();

        $cells = [];
        foreach (range(0, self::MAX_VIDEOS - 1) as $i) {
            $cells["video[{$i}].url"] = $urls->get($i, '');
        }

        return $cells;
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
