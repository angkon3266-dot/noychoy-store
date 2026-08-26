{{--
    Every search-engine-facing tag on the storefront, in one place.

    This used to be copy-pasted between inertia.blade.php and
    layouts/shop.blade.php — two copies that had already drifted, so a fix to
    one silently missed half the site. Both now include this.

    Optional view data, all provided via Inertia::render(...)->withViewData():
      $pageTitle        page title before the brand suffix
      $metaDescription  overrides the derived description
      $canonicalUrl     overrides the derived canonical
      $metaRobots       overrides the derived robots directive
      $product          App\Models\Product, product page only
      $ogImage          sharing image for non-product pages
      $seoBreadcrumbs   [['name' =>, 'url' =>], …]
      $seoItems         product cards to emit as an ItemList
      $seoListName      name for that ItemList
--}}
@php
    use App\Support\Seo\Meta;
    use App\Support\Seo\Schema;
    use Illuminate\Support\Str;

    $request = request();

    // request()->routeIs() is deliberate, not just isset($product): Blade's
    // @extends shares the child template's ENTIRE final variable table with the
    // layout, so a stray `foreach ($newArrivals as $product)` anywhere leaves
    // $product still set once the loop ends — and this head would advertise
    // that leftover product to whoever shares the link.
    $isProductPage = isset($product)
        && $product instanceof \App\Models\Product
        && $request->routeIs('product.show');

    $title = Meta::title($pageTitle ?? null);
    $robots = $metaRobots ?? Meta::robots($request);
    $canonical = $canonicalUrl ?? Meta::canonical($request);

    // Description: controller's, else the product's own (with the taka price
    // and the cash-on-delivery promise appended), else the storefront tagline.
    $metaDesc = $metaDescription ?? null;
    if (blank($metaDesc) && $isProductPage) {
        $metaDesc = Meta::productDescription($product);
    }
    if (blank($metaDesc)) {
        // home_content() rather than the controller's value, because the Blade
        // homepage templates never set one — so the owner's Google description
        // has to be reachable from here too, not only from the React branch.
        $metaDesc = home_content('seo_description')
            ?: home_content('hero_subtitle')
            ?: 'Shop '.store_name().' — handpicked jewelry delivered across Bangladesh with cash on delivery.';
    }
    $metaDesc = Str::limit(trim(strip_tags((string) $metaDesc)), 160);

    $absolute = function (?string $u) {
        $u = trim((string) $u);
        if ($u === '') {
            return null;
        }
        return Str::startsWith($u, 'http') ? $u : rtrim(config('app.url'), '/').'/'.ltrim($u, '/');
    };

    $shareImage = $isProductPage
        ? $absolute($product->thumbnail)
        : $absolute($ogImage ?? ($preloadImage ?? null) ?: theme_asset(theme('logo')));

    // One @graph rather than a pile of loose scripts, so the Product offer can
    // point at the Organization by @id instead of repeating it.
    $graph = [Schema::organization(), Schema::website()];
    if ($isProductPage) {
        $graph[] = Schema::product($product);
    }
    if (! empty($seoBreadcrumbs)) {
        $graph[] = Schema::breadcrumbs($seoBreadcrumbs);
    } elseif ($isProductPage) {
        $graph[] = Schema::breadcrumbs(array_values(array_filter([
            ['name' => 'Home', 'url' => route('home')],
            $product->category
                ? ['name' => $product->category->name, 'url' => route('category.show', $product->category->slug)]
                : null,
            ['name' => $product->name, 'url' => route('product.show', $product)],
        ])));
    }
    if (! empty($seoItems)) {
        $graph[] = Schema::itemList($seoItems, $seoListName ?? ($pageTitle ?? store_name()));
    }
@endphp
<title>{{ $title }}</title>
<meta name="description" content="{{ $metaDesc }}">
<meta name="robots" content="{{ $robots }}">
@if($canonical)<link rel="canonical" href="{{ $canonical }}">@endif

{{-- Market targeting. The shop sells only in Bangladesh, in taka, with cash on
     delivery — every signal that says so is one fewer thing Google infers from
     the (much weaker) evidence of where the server happens to sit. --}}
@if($canonical)
    <link rel="alternate" hreflang="{{ strtolower(config('seo.html_lang', 'en-BD')) }}" href="{{ $canonical }}">
    <link rel="alternate" hreflang="x-default" href="{{ $canonical }}">
@endif
<meta name="geo.region" content="{{ config('seo.geo_region') }}">
<meta name="geo.placename" content="{{ config('seo.geo_placename') }}">

{{-- Sharing card. Product pages carry the richer product:* properties. --}}
@if($isProductPage)
    <meta property="og:type" content="product">
    <meta property="og:url" content="{{ route('product.show', $product) }}">
    <meta property="product:brand" content="{{ store_name() }}">
    <meta property="product:availability" content="{{ ($product->isAvailable() || $product->isPreorder()) ? 'in stock' : 'out of stock' }}">
    <meta property="product:condition" content="new">
    <meta property="product:price:amount" content="{{ number_format((float) $product->price, 2, '.', '') }}">
    <meta property="product:price:currency" content="{{ config('store.currency', 'BDT') }}">
@else
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $canonical ?? $request->url() }}">
@endif
<meta property="og:title" content="{{ $isProductPage ? ($product->meta_title ?: $product->name) : (($pageTitle ?? null) ?: store_name()) }}">
<meta property="og:description" content="{{ $metaDesc }}">
@if($shareImage)<meta property="og:image" content="{{ $shareImage }}">@endif
<meta property="og:site_name" content="{{ store_name() }}">
<meta property="og:locale" content="{{ config('seo.og_locale', 'en_BD') }}">
<meta property="og:locale:alternate" content="bn_BD">
<meta name="twitter:card" content="{{ $shareImage ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ ($pageTitle ?? null) ?: store_name() }}">
<meta name="twitter:description" content="{{ $metaDesc }}">
@if($shareImage)<meta name="twitter:image" content="{{ $shareImage }}">@endif

<script type="application/ld+json">{!! Schema::graph($graph) !!}</script>
