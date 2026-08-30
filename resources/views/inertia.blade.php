<!DOCTYPE html>
<html lang="{{ config('seo.html_lang', 'en-BD') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Marks the document as script-capable before anything paints, so the
         pre-hydration shell below can be held back for visitors who are
         getting the real React page a moment later. --}}
    <script>document.documentElement.classList.add('js')</script>
    {{-- Every SEO tag on the site — title, description, robots, canonical,
         hreflang, OG/Twitter and the JSON-LD graph — lives in one partial
         shared with layouts/shop.blade.php. $pageTitle / $metaDescription /
         $product and friends arrive as view data from
         Inertia::render(...)->withViewData(), so crawlers and link previews
         get real server-rendered tags rather than client-rendered ones. --}}
    @include('partials.seo-head')

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
        /* The pre-hydration shell (partials/seo-body) is a real fallback: a
           crawler and a JS-less visitor read it, and it is the only HTML this
           page has until React mounts. But a visitor WITH working JavaScript
           gets the actual page a few hundred milliseconds later, and showing
           them plain document text in the meantime reads as a broken flash —
           and shifts the layout when React swaps it out.

           So it is held back only where JS is running, and only briefly: if
           the bundle is slow or fails outright, it appears rather than
           leaving a white screen. No cloaking — the shell says exactly what
           React renders, and a JS-executing crawler sees the React page. */
        .js #seo-shell { opacity: 0; animation: seo-shell-in .25s 1.2s forwards; }
        @keyframes seo-shell-in { to { opacity: 1; } }
        @media (prefers-reduced-motion: reduce) { .js #seo-shell { animation-duration: .01s; } }

        /* Logo & menu icon sized before React mounts — no flash/layout shift. */
        .logo-d { height: {{ (int) (theme('logo_height_desktop') ?: 40) }}px; width: auto; }
        .logo-m { height: {{ (int) (theme('logo_height_mobile') ?: 32) }}px; width: auto; }
        .logo-center { height: {{ (int) (theme('header_center_height') ?: 32) }}px; width: auto; }
        .menu-ico { height: {{ (int) (theme('menu_icon_height') ?: 28) }}px; width: {{ (int) (theme('menu_icon_height') ?: 28) }}px; }
    </style>
    @include('partials.meta-pixel')
</head>
<body class="min-h-screen" data-shop>
    {{-- @inertia expanded by hand so the crawlable shell can sit INSIDE the
         root element. React's createRoot().render() clears the container on
         mount, so partials.seo-body is a true pre-hydration fallback: a
         crawler and a JS-less visitor see it, a normal visitor sees it only
         for as long as the bundle takes to arrive. Re-introducing Inertia SSR
         would replace both this and the shell. --}}
    <script data-page="app" type="application/json">{!! json_encode($page, JSON_HEX_TAG) !!}</script>
    <div id="app">@include('partials.seo-body')</div>
    @include('partials.web-push')
</body>
</html>
