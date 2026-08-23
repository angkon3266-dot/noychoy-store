<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- $pageTitle / $metaDescription / $canonicalUrl / $product arrive as view
         data from Inertia::render(...)->withViewData(...) so crawlers and link
         previews get real server-rendered tags — never client-rendered. --}}
    <title>{{ ($pageTitle ?? null) ?: store_name() }} — {{ store_name() }}</title>
    @isset($product)
        @if($product instanceof \App\Models\Product && request()->routeIs('product.show'))
            @php $ogImg = $product->thumbnail; @endphp
            <meta property="og:type" content="product">
            <meta property="og:title" content="{{ $product->meta_title ?: $product->name }}">
            <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags($product->meta_description ?: $product->short_description ?: $product->description), 200) }}">
            <meta property="og:url" content="{{ route('product.show', $product) }}">
            @if($ogImg)<meta property="og:image" content="{{ \Illuminate\Support\Str::startsWith($ogImg, 'http') ? $ogImg : rtrim(config('app.url'),'/').'/'.ltrim($ogImg,'/') }}">@endif
            <meta property="product:brand" content="{{ store_name() }}">
            <meta property="product:availability" content="{{ ($product->isAvailable() || $product->isPreorder()) ? 'in stock' : 'out of stock' }}">
            <meta property="product:condition" content="new">
            <meta property="product:price:amount" content="{{ number_format((float) $product->price, 2, '.', '') }}">
            <meta property="product:price:currency" content="{{ config('store.currency', 'BDT') }}">
            @php
                $ldImages = $product->relationLoaded('images')
                    ? $product->images->map->url->take(4)->values()->all()
                    : array_filter([$ogImg]);
                $ldProduct = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => $product->name,
                    'description' => \Illuminate\Support\Str::limit(trim(strip_tags($product->short_description ?: $product->description)), 300),
                    'image' => $ldImages,
                    'sku' => $product->sku,
                    'brand' => ['@type' => 'Brand', 'name' => store_name()],
                    'offers' => [
                        '@type' => 'Offer',
                        'url' => route('product.show', $product),
                        'price' => number_format((float) $product->price, 2, '.', ''),
                        'priceCurrency' => config('store.currency', 'BDT'),
                        'availability' => ($product->isAvailable() || $product->isPreorder())
                            ? 'https://schema.org/InStock'
                            : 'https://schema.org/OutOfStock',
                    ],
                ];
                if ($product->review_count > 0 && $product->average_rating) {
                    $ldProduct['aggregateRating'] = [
                        '@type' => 'AggregateRating',
                        'ratingValue' => round((float) $product->average_rating, 1),
                        'reviewCount' => (int) $product->review_count,
                    ];
                }
                $ldCrumbs = [
                    '@context' => 'https://schema.org',
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => array_values(array_filter([
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => route('home')],
                        $product->category
                            ? ['@type' => 'ListItem', 'position' => 2, 'name' => $product->category->name, 'item' => route('category.show', $product->category->slug)]
                            : null,
                        ['@type' => 'ListItem', 'position' => $product->category ? 3 : 2, 'name' => $product->name, 'item' => route('product.show', $product)],
                    ])),
                ];
            @endphp
            <script type="application/ld+json">{!! json_encode($ldProduct, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
            <script type="application/ld+json">{!! json_encode($ldCrumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
        @endif
    @endisset
    <link rel="canonical" href="{{ $canonicalUrl ?? url()->current() }}">
    @php
        $metaDesc = $metaDescription ?? null;
        if (blank($metaDesc) && isset($product) && $product instanceof \App\Models\Product && request()->routeIs('product.show')) {
            $metaDesc = $product->meta_description ?: $product->short_description ?: $product->description;
        }
        if (blank($metaDesc)) {
            $metaDesc = home_content('hero_subtitle') ?: 'Shop '.store_name().' — quality pieces delivered across Bangladesh with cash on delivery.';
        }
        $metaDesc = \Illuminate\Support\Str::limit(trim(strip_tags((string) $metaDesc)), 160);
    @endphp
    <meta name="description" content="{{ $metaDesc }}">

    {{-- Sharing card. Product pages set their own og:* block above (with the
         richer product:* properties); every other page type gets one here, so
         a link pasted into WhatsApp or Facebook always renders with a picture
         instead of as bare text. --}}
    @php
        $absolute = function (?string $u) {
            $u = trim((string) $u);
            if ($u === '') {
                return null;
            }

            return \Illuminate\Support\Str::startsWith($u, 'http')
                ? $u
                : rtrim(config('app.url'), '/').'/'.ltrim($u, '/');
        };
        // Best available picture, in order: one the controller chose, the
        // page's own LCP image, the shop logo.
        $shareImage = $absolute($ogImage ?? ($preloadImage ?? null) ?: theme_asset(theme('logo')));
        $isProductPage = isset($product) && $product instanceof \App\Models\Product && request()->routeIs('product.show');
    @endphp
    @unless($isProductPage)
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ ($pageTitle ?? null) ?: store_name() }}">
        <meta property="og:description" content="{{ $metaDesc }}">
        <meta property="og:url" content="{{ $canonicalUrl ?? url()->current() }}">
        @if($shareImage)<meta property="og:image" content="{{ $shareImage }}">@endif
    @endunless
    <meta property="og:site_name" content="{{ store_name() }}">
    <meta property="og:locale" content="en_GB">
    <meta name="twitter:card" content="{{ $shareImage ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ ($pageTitle ?? null) ?: store_name() }}">
    <meta name="twitter:description" content="{{ $metaDesc }}">
    @if($shareImage)<meta name="twitter:image" content="{{ $shareImage }}">@endif
    @if($fav = theme_asset(theme('favicon')))<link rel="icon" href="{{ $fav }}">@else<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any"><link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">@endif
    <link rel="manifest" href="{{ route('manifest') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ store_name() }}">
    <meta name="theme-color" content="#9a6c2e">
    <link rel="apple-touch-icon" href="{{ $fav ?: asset('favicon.ico') }}">
    {{-- The body is client-rendered, so the browser cannot discover the
         largest image until React runs. The server already knows what it will
         be, so tell the browser now — this is the single biggest LCP win
         available without a Node server. --}}
    @isset($preloadImage)
        @if($preloadImage)
            <link rel="preload" as="image" href="{{ $preloadImage }}" fetchpriority="high"
                  @isset($preloadSrcset)@if($preloadSrcset) imagesrcset="{{ $preloadSrcset }}" imagesizes="{{ $preloadSizes ?? '100vw' }}"@endif @endisset>
        @endif
    @endisset
    {{-- The brand fonts. Without this the storefront rendered in whatever
         system font the phone happened to have: Instrument Sans and Playfair
         Display were being built, committed and deployed on every release, and
         no page ever emitted a @font-face for them. Self-hosted, so no
         third-party request and no Google Fonts privacy question. --}}
    @if($fontCss = brand_font_css_url())
        <link rel="stylesheet" href="{{ $fontCss }}">
    @endif
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/inertia.jsx'])

    {{-- Brand fonts + admin theme palette — identical to layouts/shop.blade.php
         so Blade and React pages render pixel-identical brand styling. --}}
    @php
        $fHeading = theme('font_heading', 'Playfair Display');
        $fHeadingSrc = theme('font_heading_src', 'google');
        $fHeadingFile = theme('font_heading_file');
        $fBody = theme('font_body', 'Instrument Sans');
        $fBodySrc = theme('font_body_src', 'google');
        $fBodyFile = theme('font_body_file');
    @endphp
    @if($fHeadingSrc === 'custom' && $fHeadingFile)
        <link rel="preload" href="{{ asset('storage/'.$fHeadingFile) }}" as="font" crossorigin>
    @endif
    @if($fBodySrc === 'custom' && $fBodyFile && $fBodyFile !== $fHeadingFile)
        <link rel="preload" href="{{ asset('storage/'.$fBodyFile) }}" as="font" crossorigin>
    @endif
    <style>
        @if($fHeadingSrc === 'custom' && $fHeadingFile)
        @font-face{ font-family:'{{ $fHeading }}'; src:url('{{ asset('storage/'.$fHeadingFile) }}'); font-weight:400 700; font-display:swap; }
        @endif
        @if($fBodySrc === 'custom' && $fBodyFile)
        @font-face{ font-family:'{{ $fBody }}'; src:url('{{ asset('storage/'.$fBodyFile) }}'); font-weight:400 700; font-display:swap; }
        @endif
        :root{
            --brand: {{ theme('primary') }};
            --accent: {{ theme('accent') }};
            --bg: {{ theme('background', '#fbf8f1') }};
            --ink: {{ theme('text', theme('accent')) }};

            --color-gold-50:  var(--bg);
            --color-gold-100: color-mix(in srgb, var(--bg) 80%, var(--brand));
            --color-gold-200: color-mix(in srgb, var(--brand) 28%, white);
            --color-gold-300: color-mix(in srgb, var(--brand) 46%, white);
            --color-gold-400: color-mix(in srgb, var(--brand) 68%, white);
            --color-gold-500: color-mix(in srgb, var(--brand) 86%, white);
            --color-gold-600: var(--brand);
            --color-gold-700: color-mix(in srgb, var(--brand) 82%, black);
            --color-gold-800: color-mix(in srgb, var(--brand) 64%, black);
            --color-gold-900: color-mix(in srgb, var(--brand) 52%, black);
            --color-ink-900: var(--ink);
            --color-ink-800: color-mix(in srgb, var(--ink) 90%, white);
            --color-ink-700: color-mix(in srgb, var(--ink) 78%, white);
            --color-accent: var(--accent);

            --font-sans: '{{ $fBody }}', ui-sans-serif, system-ui, sans-serif;
            --font-serif: '{{ $fHeading }}', Georgia, 'Times New Roman', serif;
        }
        /* Logo & menu icon sized before React mounts — no flash/layout shift. */
        .logo-d { height: {{ (int) (theme('logo_height_desktop') ?: 40) }}px; width: auto; }
        .logo-m { height: {{ (int) (theme('logo_height_mobile') ?: 32) }}px; width: auto; }
        .logo-center { height: {{ (int) (theme('header_center_height') ?: 32) }}px; width: auto; }
        .menu-ico { height: {{ (int) (theme('menu_icon_height') ?: 28) }}px; width: {{ (int) (theme('menu_icon_height') ?: 28) }}px; }
    </style>
    @include('partials.meta-pixel')
</head>
<body class="min-h-screen" data-shop>
    @inertia
    @include('partials.web-push')
</body>
</html>
