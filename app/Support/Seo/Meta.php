<?php

namespace App\Support\Seo;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Titles, descriptions, robots directives and canonical URLs.
 *
 * Two problems this fixes.
 *
 * First, index bloat. Every catalog filter and sort is a GET parameter, so
 * `/category/rings` also exists as `?sort=price_asc`, `?colors[]=gold`,
 * `?price_range[]=…` and every combination of them. All of it was crawlable
 * and self-canonicalising, which on a 134-URL site means the crawl budget is
 * spent re-reading the same 20 rings instead of finding new products.
 *
 * Second, the titles said nothing about Bangladesh. A Bangladeshi shopper
 * types "silver ring price in bangladesh", not "silver ring" — the qualifier
 * is the query, and leaving it out of the title leaves the match to chance.
 * Admin-written meta titles always win; this only fills the blanks.
 */
class Meta
{
    /** Query parameters that turn a catalog page into a filtered view. */
    private const FILTER_PARAMS = [
        'q', 'sort', 'category', 'price_range', 'price_min', 'price_max',
        'attr', 'cf', 'colors', 'tags',
    ];

    /**
     * Route names that must never be indexed. robots.txt already discourages
     * crawling most of them, but a Disallow only stops the fetch — a URL
     * linked from anywhere can still be indexed title-less. A meta robots tag
     * is the only thing that actually removes a page from the index, and it
     * requires the page to stay crawlable, which is why both exist.
     */
    private const NEVER_INDEX = [
        'cart', 'cart.*',
        'checkout', 'checkout.*',
        'order.*',            // confirmation, review invite, account claim
        'track',
        'account', 'account.*',
        'customer.*',         // login, register, logout, password reset, Google
        'review.*',
        'search.suggest',
    ];

    /**
     * The <title>. "Fine Jewelry — Meridian Éclat", never
     * "Meridian Éclat — Meridian Éclat" (which is what an empty page title
     * used to produce on any page whose controller forgot to set one).
     */
    public static function title(?string $pageTitle): string
    {
        $store = store_name();
        $page = trim((string) $pageTitle);

        if ($page === '' || mb_strtolower($page) === mb_strtolower($store)) {
            return $store;
        }

        // An admin-written meta title that already names the brand should not
        // have it bolted on a second time.
        if (Str::contains(mb_strtolower($page), mb_strtolower($store))) {
            return $page;
        }

        // A pipe, not a dash: the qualifier appended to product and category
        // titles already uses a dash, and "Hoop Earrings — Price in Bangladesh
        // — Meridian Éclat" reads as one long stutter.
        return $page.' | '.$store;
    }

    /**
     * Page title for a product. The admin's meta_title wins outright; when
     * there isn't one we append the market qualifier, which is the half of the
     * query the product name never contains.
     */
    public static function productTitle(Product $product): string
    {
        if (filled($product->meta_title)) {
            return $product->meta_title;
        }

        $qualifier = trim((string) config('seo.title_qualifier'));

        return $qualifier === ''
            ? $product->name
            : $product->name.' — '.$qualifier;
    }

    /**
     * Meta description for a product with none written. Leads with the shop's
     * own words, then states the two things that decide a Bangladeshi purchase
     * — the price in taka, and that no money changes hands before delivery.
     */
    public static function productDescription(Product $product): string
    {
        if (filled($product->meta_description)) {
            return Str::limit(trim(strip_tags($product->meta_description)), 160);
        }

        $lead = plain_copy(strip_tags((string) ($product->short_description ?: $product->description)));
        $lead = preg_replace('/\s+/u', ' ', $lead) ?? '';

        $price = config('store.currency_symbol', '৳').number_format((float) $product->price);
        // Not "<name> at <price>" — the name is almost always the first words
        // of the lead sentence already, and Google's snippet is too short to
        // spend twice on the same phrase.
        $tail = 'Price '.$price.'. Cash on delivery all over Bangladesh.';

        // Budget the lead so the whole thing survives Google's ~160-char cut
        // with the price and the COD promise intact.
        $room = 158 - mb_strlen($tail);
        $lead = $room > 40 ? Str::limit($lead, $room, '') : '';

        return trim(trim($lead).' '.$tail);
    }

    /**
     * Page title for a catalog grid. Category and collection names are product
     * nouns ("Rings", "Fashion Earring"), so they take the same market
     * qualifier as a product; the fixed grids read better with their own.
     */
    public static function catalogTitle(string $name, bool $qualify = true): string
    {
        $qualifier = trim((string) config('seo.title_qualifier'));

        if (! $qualify || $qualifier === '' || Str::contains(mb_strtolower($name), 'bangladesh')) {
            return $name;
        }

        return $name.' — '.$qualifier;
    }

    /**
     * Meta description for a catalog page with none written.
     */
    public static function catalogDescription(string $title, ?string $written, ?int $count = null): string
    {
        if (filled($written)) {
            return Str::limit(trim(strip_tags($written)), 160);
        }

        $howMany = $count && $count > 0 ? $count.' designs' : 'designs';

        return Str::limit(
            'Buy '.$title.' online in Bangladesh — '.$howMany.' from '.store_name()
            .'. Cash on delivery nationwide, delivered by courier inside and outside Dhaka.',
            160
        );
    }

    /**
     * The robots directive for this request.
     *
     * max-image-preview:large is not boilerplate here: this is a jewelry shop,
     * the photography is the product, and large previews are what earns the
     * click in Google Images and Discover — both of which are a bigger share of
     * Bangladeshi mobile discovery than desktop web results.
     */
    public static function robots(Request $request): string
    {
        if (config('seo.robots') === 'noindex') {
            return 'noindex, nofollow';
        }

        if (self::isNeverIndexed($request) || self::isFiltered($request)) {
            // follow, so link equity still reaches the products listed on a
            // filtered grid even though the grid itself stays out of the index.
            return 'noindex, follow';
        }

        return 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
    }

    /**
     * The canonical URL, or null when the page should not declare one.
     *
     * Filtered and never-indexed pages get no canonical at all rather than one
     * pointing at the clean URL: noindex plus a canonical elsewhere is a pair
     * of contradictory instructions, and Google resolves the contradiction
     * however it likes. Say one thing.
     */
    public static function canonical(Request $request): ?string
    {
        if (self::isNeverIndexed($request) || self::isFiltered($request)) {
            return null;
        }

        $url = $request->url();

        // Page 2 of a grid is its own page and must say so. It used to
        // canonicalise to page 1, which told Google the two were the same and
        // quietly discarded every product only reachable from page 2+.
        $page = (int) $request->query('page', 1);

        return $page > 1 ? $url.'?page='.$page : $url;
    }

    /** True when the request carries a search term, sort or facet. */
    public static function isFiltered(Request $request): bool
    {
        foreach (self::FILTER_PARAMS as $param) {
            if (filled($request->query($param))) {
                return true;
            }
        }

        return false;
    }

    private static function isNeverIndexed(Request $request): bool
    {
        return $request->routeIs(...self::NEVER_INDEX);
    }
}
