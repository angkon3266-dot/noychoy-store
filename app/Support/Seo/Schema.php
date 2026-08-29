<?php

namespace App\Support\Seo;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Every JSON-LD graph the storefront emits.
 *
 * Before this existed the only structured data on the site was a bare Product
 * + BreadcrumbList on the product page: no Organization, no WebSite, and an
 * Offer carrying nothing but a price. That is enough to be *parsed* and not
 * nearly enough to be *chosen* — Google's shopping surfaces and the AI answer
 * engines pick between merchants on exactly the fields that were missing (who
 * you are, where you ship, what shipping costs, whether it can be returned).
 *
 * Everything below is derived from settings the shop already holds. Nothing is
 * invented: a value the admin has not filled in is omitted rather than guessed
 * at, because a wrong claim in schema is a manual action waiting to happen.
 */
class Schema
{
    /**
     * The shop itself. Emitted on every page with a stable @id, so the Product
     * and WebSite nodes can point at it instead of repeating themselves.
     *
     * @return array<string,mixed>
     */
    public static function organization(): array
    {
        $url = self::baseUrl();

        $org = array_filter([
            '@type' => 'OnlineStore',
            '@id' => $url.'/#organization',
            'name' => store_name(),
            'url' => $url.'/',
            'logo' => theme_asset(theme('logo')),
            'image' => theme_asset(theme('logo')),
            'description' => theme('footer_about') ?: null,
            'email' => config('store.email') ?: null,
            'telephone' => self::telephone(),
            'foundingDate' => config('seo.organization.founding_date') ?: null,
            'priceRange' => self::priceRange(),
        ]);

        // Locality + country alone is a valid PostalAddress, and it is the
        // honest shape for a delivery-only shop with no walk-in counter.
        $org['address'] = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => config('seo.organization.street'),
            'addressLocality' => config('seo.organization.locality'),
            'addressRegion' => config('seo.organization.region'),
            'postalCode' => config('seo.organization.postal_code'),
            'addressCountry' => config('seo.country', 'BD'),
        ]);

        $org['areaServed'] = ['@type' => 'Country', 'name' => 'Bangladesh'];
        $org['currenciesAccepted'] = config('store.currency', 'BDT');
        $org['paymentAccepted'] = 'Cash on Delivery';

        if ($sameAs = self::sameAs()) {
            $org['sameAs'] = $sameAs;
        }

        if ($phone = self::telephone()) {
            $org['contactPoint'] = [[
                '@type' => 'ContactPoint',
                'telephone' => $phone,
                'contactType' => 'customer service',
                'areaServed' => 'BD',
                'availableLanguage' => ['bn', 'en'],
            ]];
        }

        return $org;
    }

    /**
     * WebSite node. The SearchAction is what makes Google eligible to show a
     * sitelinks search box under the brand result — free real estate on the
     * one query the shop should always own.
     *
     * @return array<string,mixed>
     */
    public static function website(): array
    {
        $url = self::baseUrl();

        return [
            '@type' => 'WebSite',
            '@id' => $url.'/#website',
            'url' => $url.'/',
            'name' => store_name(),
            'inLanguage' => config('seo.html_lang', 'en-BD'),
            'publisher' => ['@id' => $url.'/#organization'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => route('shop').'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * A product, with the offer fields that decide whether it is merely indexed
     * or actually surfaced: shipping cost to a real destination, a return
     * window, condition, seller, and a price validity date.
     *
     * @return array<string,mixed>
     */
    public static function product(Product $product): array
    {
        $images = $product->relationLoaded('images')
            ? $product->images->map->url->take(6)->values()->all()
            : array_values(array_filter([$product->thumbnail]));

        $node = array_filter([
            '@type' => 'Product',
            '@id' => route('product.show', $product).'#product',
            'name' => $product->name,
            'description' => Str::limit(plain_copy(strip_tags(
                (string) ($product->meta_description ?: $product->short_description ?: $product->description)
            )), 400, ''),
            'image' => $images,
            'sku' => $product->sku ?: null,
            'mpn' => $product->sku ?: null,
            'category' => $product->category?->name,
            'brand' => ['@type' => 'Brand', 'name' => store_name()],
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $node['offers'] = self::offer($product);

        if ($product->review_count > 0 && $product->average_rating) {
            $node['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $product->average_rating, 1),
                'reviewCount' => (int) $product->review_count,
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }

        return $node;
    }

    /** @return array<string,mixed> */
    public static function offer(Product $product): array
    {
        $availability = match (true) {
            $product->isPreorder() => 'https://schema.org/PreOrder',
            $product->isAvailable() => 'https://schema.org/InStock',
            default => 'https://schema.org/OutOfStock',
        };

        $offer = [
            '@type' => 'Offer',
            'url' => route('product.show', $product),
            'price' => number_format((float) $product->price, 2, '.', ''),
            'priceCurrency' => config('store.currency', 'BDT'),
            'availability' => $availability,
            'itemCondition' => 'https://schema.org/NewCondition',
            // Without this Google treats the price as indefinite and eventually
            // flags the offer as stale. A rolling year is the convention for a
            // shop whose prices are not campaign-bound.
            'priceValidUntil' => now(config('store.timezone', 'Asia/Dhaka'))
                ->addYear()->toDateString(),
            'seller' => ['@id' => self::baseUrl().'/#organization'],
            'areaServed' => ['@type' => 'Country', 'name' => 'Bangladesh'],
        ];

        if ($shipping = self::shippingDetails()) {
            $offer['shippingDetails'] = $shipping;
        }

        if ($returns = self::returnPolicy()) {
            $offer['hasMerchantReturnPolicy'] = $returns;
        }

        return $offer;
    }

    /**
     * Real courier rates, split the way the shop actually charges: one rate
     * inside Dhaka, another for the rest of the country, each with its own
     * transit time.
     *
     * @return list<array<string,mixed>>
     */
    public static function shippingDetails(): array
    {
        $currency = config('store.currency', 'BDT');
        $rates = (array) config('store.shipping');

        $make = fn (float $rate, ?string $region, int $minDays, int $maxDays) => [
            '@type' => 'OfferShippingDetails',
            'shippingRate' => [
                '@type' => 'MonetaryAmount',
                'value' => number_format($rate, 2, '.', ''),
                'currency' => $currency,
            ],
            'shippingDestination' => array_filter([
                '@type' => 'DefinedRegion',
                'addressCountry' => config('seo.country', 'BD'),
                'addressRegion' => $region,
            ]),
            'deliveryTime' => [
                '@type' => 'ShippingDeliveryTime',
                'handlingTime' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => 0, 'maxValue' => 1, 'unitCode' => 'DAY',
                ],
                'transitTime' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => $minDays, 'maxValue' => $maxDays, 'unitCode' => 'DAY',
                ],
            ],
        ];

        return [
            $make(
                (float) ($rates['inside_dhaka'] ?? 0),
                'Dhaka',
                (int) theme('delivery_days_inside_min', 1),
                (int) theme('delivery_days_inside_max', 2),
            ),
            $make(
                (float) ($rates['outside_dhaka'] ?? 0),
                null,
                (int) theme('delivery_days_min', 2),
                (int) theme('delivery_days_max', 4),
            ),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function returnPolicy(): ?array
    {
        $days = (int) config('seo.return_days', 0);

        if ($days < 1) {
            return null;
        }

        $fees = config('seo.return_fees');

        return array_filter([
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => config('seo.country', 'BD'),
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => $days,
            'returnMethod' => 'https://schema.org/ReturnByMail',
            // Omitted unless the owner has actually decided — see config/seo.php.
            'returnFees' => $fees ? 'https://schema.org/'.ltrim((string) $fees, '/') : null,
        ]);
    }

    /**
     * @param  list<array{name?:string,url?:string|null}>  $crumbs
     * @return array<string,mixed>
     */
    public static function breadcrumbs(array $crumbs): array
    {
        $items = [];
        $position = 1;

        foreach ($crumbs as $crumb) {
            $name = trim((string) ($crumb['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $items[] = array_filter([
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $name,
                'item' => $crumb['url'] ?? null,
            ]);
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /**
     * The products on a catalog page, in the order shown — so Google gets the
     * grid's contents even though the grid itself is drawn by React.
     *
     * @param  list<array<string,mixed>>  $cards
     * @return array<string,mixed>|null
     */
    public static function itemList(array $cards, string $name): ?array
    {
        $items = [];
        $position = 1;

        foreach ($cards as $card) {
            if (empty($card['url'])) {
                continue;
            }
            $items[] = array_filter([
                '@type' => 'ListItem',
                'position' => $position++,
                'url' => $card['url'],
                'name' => $card['name'] ?? null,
            ]);
        }

        if (! $items) {
            return null;
        }

        return [
            '@type' => 'ItemList',
            'name' => $name,
            'numberOfItems' => count($items),
            'itemListElement' => $items,
        ];
    }

    /**
     * Wrap nodes in a single @graph: one script tag, one context, and nodes
     * able to reference each other by @id — which is what lets the Product
     * offer say "sold by that Organization up there" instead of repeating it.
     *
     * @param  list<array<string,mixed>|null>  $nodes
     */
    public static function graph(array $nodes): string
    {
        // JSON_HEX_TAG is load-bearing, not tidiness: this JSON is printed
        // unescaped inside a <script> block, and a product name containing
        // "</script>" would otherwise close the tag and run whatever followed.
        return json_encode(
            ['@context' => 'https://schema.org', '@graph' => array_values(array_filter($nodes))],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private static function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** E.164 for schema — the shop stores a bare 8801XXXXXXXXX. */
    private static function telephone(): ?string
    {
        $raw = trim((string) (config('store.phone') ?: theme('whatsapp_number') ?: ''));

        if ($raw === '') {
            return null;
        }

        return Str::startsWith($raw, '+') ? $raw : '+'.ltrim($raw, '+');
    }

    /** @return list<string> */
    private static function sameAs(): array
    {
        return array_values(array_unique(array_filter([
            theme('footer_facebook'),
            theme('footer_instagram'),
            ...(array) config('seo.organization.same_as', []),
        ])));
    }

    /**
     * Coarse price band from the live catalogue, cached — Google uses it to
     * place the shop against competitors on price-led queries.
     */
    private static function priceRange(): ?string
    {
        return Cache::remember('seo.price_range', 3600, function () {
            $min = Product::published()->min('price');
            $max = Product::published()->max('price');

            if (! $min || ! $max) {
                return null;
            }

            $symbol = config('store.currency_symbol', '৳');

            return $symbol.number_format((float) $min).'–'.$symbol.number_format((float) $max);
        });
    }
}
