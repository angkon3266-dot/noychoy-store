<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Product catalogue feeds, one per channel.
 *
 * Meta (Facebook/Instagram) — CSV. Paste the URL into Commerce Manager →
 * Catalog → Data sources → Scheduled feed. Use product_type / custom_label_N
 * to build product sets per category for ads.
 *
 * Google — RSS 2.0 XML. Paste the URL into Merchant Center → Data sources →
 * Add products from a file → scheduled fetch.
 *
 * Both walk the same products via publishedProducts(), but the field formats
 * are genuinely different and must not be collapsed into one feed.
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

            $this->publishedProducts()
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
                            'description' => feed_description($p),
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
     * Google Shopping feed — RSS 2.0, the format Merchant Center's "Add
     * products from a file" data source fetches on a schedule.
     *
     * Deliberately not the Meta CSV renamed: Google rejects rather than ignores
     * the differences — underscored availability, a shipping block, and
     * identifier_exists for a catalogue that carries no GTINs.
     */
    public function google(Request $request): StreamedResponse
    {
        $brand = config('meta.defaults.brand') ?: store_name();
        $currency = config('store.currency', 'BDT');
        $shipping = (float) Setting::get('shipping_inside', config('store.shipping.inside_dhaka'));

        $headers = [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'inline; filename="google-shopping.xml"',
        ];

        return response()->stream(function () use ($brand, $currency, $shipping) {
            $out = fopen('php://output', 'w');
            $money = fn (float $v) => number_format($v, 2, '.', '').' '.$currency;

            // One flat rate covers the whole catalogue, so render it once.
            $shippingXml = "  <g:shipping>\n  ".$this->el('g:country', 'BD')
                .'  '.$this->el('g:price', $money($shipping))."  </g:shipping>\n";

            fwrite($out, '<?xml version="1.0" encoding="UTF-8"?>'."\n"
                .'<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">'."\n<channel>\n"
                .$this->el('title', store_name())
                .$this->el('link', rtrim(config('app.url'), '/').'/')
                .$this->el('description', store_name().' product catalogue'));

            $this->publishedProducts()
                ->chunk(200, function ($products) use ($out, $brand, $money, $shippingXml) {
                    foreach ($products as $p) {
                        $images = $p->images;
                        $primary = $images->firstWhere('is_primary', true) ?? $images->first();
                        if (! $primary) {
                            continue; // Google requires an image_link
                        }

                        $base = [
                            // Same id the Meta feed and the Pixel/CAPI use, so
                            // one product is one string everywhere.
                            'g:id' => meta_content_id($p),
                            'g:item_group_id' => null,
                            'title' => Str::limit($p->name, 150, ''),
                            'description' => feed_description($p),
                            'link' => route('product.show', $p),
                            'g:image_link' => $this->absUrl($primary->url),
                            'g:additional_image_link' => $images->where('id', '!=', $primary->id)->take(10)
                                ->map(fn ($i) => $this->absUrl($i->url))->values()->all(),
                            'g:condition' => 'new',
                            'g:brand' => $brand,
                            'g:google_product_category' => $p->googleCategory() ?: null,
                            'g:product_type' => $p->categories->pluck('name')->implode(' > ') ?: null,
                            // Nothing here carries a manufacturer identifier —
                            // the SKU is our own code, not an MPN. Declaring
                            // that is what stops Google disapproving every item
                            // for a missing GTIN.
                            'g:identifier_exists' => 'no',
                            'g:availability' => null,
                            'g:price' => null,
                            'g:sale_price' => null,
                            'g:color' => null,
                            'g:size' => null,
                            'g:material' => null,
                        ];

                        // Variable products: one item per variant, grouped under
                        // the parent so Google shows them as one product family.
                        if ($p->has_variants && $p->variants->isNotEmpty()) {
                            foreach ($p->variants as $v) {
                                $price = $v->price !== null ? (float) $v->price : (float) $p->price;
                                $compare = $p->compare_at_price ? (float) $p->compare_at_price : null;
                                $attrs = collect($v->attributes ?? []);

                                if ($price <= 0) {
                                    continue; // see the guard on simple products
                                }

                                $this->googleItem($out, $base, [
                                    'g:id' => meta_content_id($p, $v),
                                    'g:item_group_id' => meta_content_id($p),
                                    'title' => Str::limit(trim($p->name.' '.$v->label), 150, ''),
                                    'g:image_link' => $this->absUrl($v->image?->url ?: $primary->url),
                                    'g:availability' => ((int) $v->stock_quantity > 0 || $p->isPreorder()) ? 'in_stock' : 'out_of_stock',
                                    'g:price' => $money($compare ?: $price),
                                    'g:sale_price' => $compare && $price < $compare ? $money($price) : null,
                                    'g:color' => $this->variantAttr($attrs, ['color', 'colour']),
                                    'g:size' => $this->variantAttr($attrs, ['size']),
                                    'g:material' => $this->variantAttr($attrs, ['material']),
                                ], $shippingXml);
                            }

                            continue;
                        }

                        // A price of 0 means the product is unpriced, not free.
                        // Google rejects it either way, and shipping it would
                        // advertise a giveaway — so leave it out of the feed.
                        if ((float) $p->price <= 0) {
                            continue;
                        }

                        $this->googleItem($out, $base, [
                            'g:availability' => ($p->isAvailable() || $p->isPreorder()) ? 'in_stock' : 'out_of_stock',
                            'g:price' => $money((float) ($p->compare_at_price ?: $p->price)),
                            'g:sale_price' => $p->is_on_sale ? $money((float) $p->price) : null,
                            'g:color' => collect($p->colors ?? [])->filter()->first(),
                        ], $shippingXml);
                    }
                });

            fwrite($out, "</channel>\n</rss>\n");
            fclose($out);
        }, 200, $headers);
    }

    /**
     * The published products both feeds walk, including the optional
     * ?category= filter.
     *
     * The closure around the category match is load-bearing: without it the
     * orWhereHas escapes published(), and ?category=x compiled to
     * "(published AND pivot-match) OR primary-match" — which fed DRAFT products
     * to the catalogue.
     */
    protected function publishedProducts(): Builder
    {
        return Product::published()
            ->with(['images', 'category', 'categories', 'variants'])
            ->when(request('category'), function ($q, $slug) {
                $q->where(fn ($w) => $w->whereHas('categories', fn ($c) => $c->where('slug', $slug))
                    ->orWhereHas('category', fn ($c) => $c->where('slug', $slug)));
            });
    }

    /**
     * Write one <item>, dropping empty fields — Google reads an empty element
     * as a malformed value rather than an absent one. An array value repeats
     * the element, which is how additional_image_link carries more than one.
     *
     * @param  array<string, string|array<int, string>|null>  $fields
     * @param  array<string, string|null>  $over
     */
    protected function googleItem($out, array $fields, array $over, string $trailingXml = ''): void
    {
        fwrite($out, "<item>\n");

        foreach (array_replace($fields, $over) as $name => $value) {
            foreach ((array) $value as $single) {
                if ($single === null || $single === '') {
                    continue;
                }

                fwrite($out, $this->el($name, (string) $single));
            }
        }

        fwrite($out, $trailingXml."</item>\n");
    }

    /** One element, escaped so a stray & or < in a title cannot break the feed. */
    protected function el(string $name, string $value): string
    {
        return '  <'.$name.'>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</'.$name.">\n";
    }

    /** Read a named variant attribute regardless of how it was capitalised. */
    protected function variantAttr(Collection $attrs, array $names): ?string
    {
        foreach ($attrs as $key => $value) {
            if (in_array(strtolower((string) $key), $names, true)) {
                return (string) $value;
            }
        }

        return null;
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
