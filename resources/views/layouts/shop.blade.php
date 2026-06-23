<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('store.name')) — {{ config('store.name') }}</title>
    @hasSection('meta')@yield('meta')@endif
    @isset($product)
        @if($product instanceof \App\Models\Product)
            @php $ogImg = $product->thumbnail; @endphp
            <meta property="og:type" content="product">
            <meta property="og:title" content="{{ $product->meta_title ?: $product->name }}">
            <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags($product->meta_description ?: $product->short_description ?: $product->description), 200) }}">
            <meta property="og:url" content="{{ route('product.show', $product) }}">
            @if($ogImg)<meta property="og:image" content="{{ \Illuminate\Support\Str::startsWith($ogImg, 'http') ? $ogImg : rtrim(config('app.url'),'/').'/'.ltrim($ogImg,'/') }}">@endif
            <meta property="product:brand" content="{{ config('store.name') }}">
            <meta property="product:availability" content="{{ ($product->isAvailable() || $product->isPreorder()) ? 'in stock' : 'out of stock' }}">
            <meta property="product:condition" content="new">
            <meta property="product:price:amount" content="{{ number_format((float) $product->price, 2, '.', '') }}">
            <meta property="product:price:currency" content="{{ config('store.currency', 'BDT') }}">
        @endif
    @endisset
    @if($fav = theme_asset(theme('favicon')))<link rel="icon" href="{{ $fav }}">@endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>window.__cartCount = {{ $cartCount ?? 0 }};</script>
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    {{-- Brand fonts (Google and/or uploaded custom) --}}
    @php
        $fHeading = theme('font_heading', 'Playfair Display');
        $fHeadingSrc = theme('font_heading_src', 'google');
        $fHeadingFile = theme('font_heading_file');
        $fBody = theme('font_body', 'Instrument Sans');
        $fBodySrc = theme('font_body_src', 'google');
        $fBodyFile = theme('font_body_file');
        $googleFonts = collect();
        if ($fHeadingSrc === 'google' && $fHeading) $googleFonts->push($fHeading);
        if ($fBodySrc === 'google' && $fBody) $googleFonts->push($fBody);
        $googleFonts = $googleFonts->filter()->unique()->values();
    @endphp
    @if($googleFonts->isNotEmpty())
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?{{ $googleFonts->map(fn($f) => 'family='.str_replace(' ', '+', $f).':wght@400;500;600;700')->implode('&') }}&display=swap" rel="stylesheet">
    @endif
    <style>
        @if($fHeadingSrc === 'custom' && $fHeadingFile)
        @font-face{ font-family:'{{ $fHeading }}'; src:url('{{ asset('storage/'.$fHeadingFile) }}'); font-weight:400 700; font-display:swap; }
        @endif
        @if($fBodySrc === 'custom' && $fBodyFile)
        @font-face{ font-family:'{{ $fBody }}'; src:url('{{ asset('storage/'.$fBodyFile) }}'); font-weight:400 700; font-display:swap; }
        @endif
        :root{
            /* 4-colour brand palette */
            --brand: {{ theme('primary') }};
            --accent: {{ theme('accent') }};
            --bg: {{ theme('background', '#fbf8f1') }};
            --ink: {{ theme('text', theme('accent')) }};

            /* Surfaces follow Background, accents/buttons follow Primary */
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
            /* Text / ink follows Text colour */
            --color-ink-900: var(--ink);
            --color-ink-800: color-mix(in srgb, var(--ink) 90%, white);
            --color-ink-700: color-mix(in srgb, var(--ink) 78%, white);
            /* Secondary accent */
            --color-accent: var(--accent);

            --font-sans: '{{ $fBody }}', ui-sans-serif, system-ui, sans-serif;
            --font-serif: '{{ $fHeading }}', Georgia, 'Times New Roman', serif;
        }
        .bg-accent{ background-color: var(--color-accent) !important; }
        .text-accent{ color: var(--color-accent) !important; }
        .border-accent{ border-color: var(--color-accent) !important; }
        [x-cloak]{display:none!important}

        /* Moving announcement bar */
        .announce-marquee{ display:flex; overflow:hidden; white-space:nowrap; }
        .announce-track{
            display:flex; align-items:center; flex-shrink:0; min-width:100%;
            animation: announce-scroll linear infinite;
            will-change: transform;
        }
        .announce-item{ padding:0 .25rem; }
        .announce-sep{ padding:0 .85rem; opacity:.55; }
        .announce-bar:hover .announce-track{ animation-play-state: paused; }
        @keyframes announce-scroll{
            from{ transform: translateX(0); }
            to{ transform: translateX(-100%); }
        }
        @media (prefers-reduced-motion: reduce){
            .announce-track{ animation: none; }
            .announce-marquee{ justify-content:center; }
            .announce-track:nth-child(2){ display:none; }
        }
    </style>
    @include('partials.meta-pixel')
</head>
<body class="min-h-screen flex flex-col">
    @php
        $announce = theme();
        $announceMsgs = array_values(array_filter((array) ($announce['announcement_messages'] ?? [])));
    @endphp
    @if($announce['announcement_enabled'] && !empty($announceMsgs))
        @php $announceSpeed = max(10, count($announceMsgs) * (int) ($announce['announcement_speed'] ?? 6)); @endphp
        <div x-data="{ show: true }" x-show="show"
             class="announce-bar relative text-xs"
             style="background: {{ $announce['announcement_bg'] }}; color: {{ $announce['announcement_color'] }}">
            <div class="announce-marquee py-2">
                {{-- duplicated track for a seamless infinite loop --}}
                @for($pass = 0; $pass < 2; $pass++)
                    <div class="announce-track" style="animation-duration: {{ $announceSpeed }}s" aria-hidden="{{ $pass ? 'true' : 'false' }}">
                        @foreach($announceMsgs as $m)
                            <span class="announce-item">
                                @if($announce['announcement_link'])
                                    <a href="{{ $announce['announcement_link'] }}" class="hover:underline">{{ $m }}</a>
                                @else
                                    {{ $m }}
                                @endif
                            </span>
                            <span class="announce-sep" aria-hidden="true">✦</span>
                        @endforeach
                    </div>
                @endfor
            </div>
            <button @click="show=false" aria-label="Dismiss"
                    class="absolute right-2 top-1/2 -translate-y-1/2 px-1.5 opacity-60 hover:opacity-100"
                    style="background: {{ $announce['announcement_bg'] }};">×</button>
        </div>
    @endif

    @php
        $logoHM = (int) (theme('logo_height_mobile') ?: 32);
        $logoHD = (int) (theme('logo_height_desktop') ?: 40);
        $centerH = (int) (theme('header_center_height') ?: 32);
        $logoDesktop = theme_asset(theme('logo'));
        $logoMobileUp = theme_asset(theme('logo_mobile'));   // explicit mobile upload (if any)
        $logoMobile = $logoMobileUp ?: $logoDesktop;          // fall back to desktop only when no mobile logo
        $headerCenter = theme_asset(theme('header_center_image'));
        $logoAlign = theme('logo_align', 'left');
        // Alignment classes for the logo anchor inside the header row.
        $logoAlignClass = $logoAlign === 'center' ? 'absolute left-1/2 -translate-x-1/2' : ($logoAlign === 'right' ? 'ml-auto' : '');
    @endphp
    <style>
        .logo-d { height: {{ $logoHD }}px; }
        .logo-m { height: {{ $logoHM }}px; }
        .logo-center { height: {{ $centerH }}px; }
    </style>
    @php $menuIcon = theme_asset(theme('menu_icon')); $menuRot = (int) (theme('menu_icon_rotation') ?? 45); $menuIconH = (int) (theme('menu_icon_height') ?: 28); @endphp
    <header class="sticky top-0 z-40 bg-gold-50/95 backdrop-blur border-b border-gold-200" x-data="{ open: false, msearch: false, rot: {{ $menuRot }} }">
        <div class="mx-auto max-w-7xl px-4">
            <div class="relative flex h-16 items-center gap-2">
                {{-- Mobile menu toggle (far left) — uses the uploaded icon, rotating when open --}}
                <button @click="open = !open" class="md:hidden p-2 -ml-1" aria-label="Menu" :aria-expanded="open">
                    @if($menuIcon)
                        <img src="{{ $menuIcon }}" alt="Menu" class="object-contain"
                             :style="`height: {{ $menuIconH }}px; width: {{ $menuIconH }}px; transform: rotate(${open ? rot : 0}deg); transition: transform 300ms ease`">
                    @else
                        <svg class="w-6 h-6 transition-transform duration-300" :style="`transform: rotate(${open ? rot : 0}deg)`" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/></svg>
                    @endif
                </button>

                {{-- Logo. Separate image for desktop & mobile; placement configurable. --}}
                <a href="{{ route('home') }}" class="shrink-0 {{ $logoAlignClass }}">
                    @if($logoDesktop || $logoMobile)
                        @if($logoDesktop)<img src="{{ $logoDesktop }}" alt="{{ config('store.name') }}" class="logo-d w-auto hidden md:block">@endif
                        @if($logoMobile)<img src="{{ $logoMobile }}" alt="{{ config('store.name') }}" class="logo-m w-auto md:hidden">@endif
                    @else
                        <span class="logo-m md:logo-d inline-flex items-center font-display font-bold tracking-wide text-gold-700" style="font-size: calc({{ $logoHM }}px * 0.55)">{{ \App\Models\Setting::get('store_name', config('store.name')) }}</span>
                    @endif
                </a>

                {{-- Optional center image (mobile only) --}}
                @if($headerCenter)
                    <a href="{{ theme('header_center_link') ?: route('home') }}" class="md:hidden absolute left-1/2 -translate-x-1/2">
                        <img src="{{ $headerCenter }}" alt="" class="logo-center w-auto">
                    </a>
                @endif

                @php $menuTrigger = theme('menu_desktop_trigger', 'hover'); @endphp
                <nav class="hidden md:flex items-center gap-6 text-sm font-medium">
                    @foreach($siteMenu ?? [] as $item)
                        @php $mtype = $item['type'] ?? (! empty($item['columns']) ? 'mega' : (! empty($item['children']) ? 'dropdown' : 'link')); @endphp
                        @if($mtype === 'link')
                            <a href="{{ $item['url'] }}" @if($item['new_tab']) target="_blank" rel="noopener" @endif class="hover:text-gold-700 flex items-center gap-1">
                                {{ $item['label'] }}
                                @if($item['badge'] ?? false)<span class="badge bg-gold-600 text-white text-[9px]">{{ $item['badge'] }}</span>@endif
                            </a>
                        @else
                            <div class="{{ $mtype === 'mega' ? 'static' : 'relative' }}" x-data="{ o: false }"
                                 @if($menuTrigger === 'hover') @mouseenter="o=true" @mouseleave="o=false" @endif>
                                <button type="button" @if($menuTrigger === 'click') @click="o=!o" @endif class="flex items-center gap-1 hover:text-gold-700">
                                    {{ $item['label'] }}
                                    @if($item['badge'] ?? false)<span class="badge bg-gold-600 text-white text-[9px]">{{ $item['badge'] }}</span>@endif
                                    <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                @if($mtype === 'mega')
                                    {{-- Full-width mega panel --}}
                                    <div x-show="o" x-cloak @click.outside="o=false" x-transition.opacity
                                         class="absolute left-0 right-0 top-full pt-3 z-50">
                                        <div class="mx-auto max-w-7xl rounded-xl border border-gold-100 bg-white shadow-xl p-6 grid gap-6"
                                             style="grid-template-columns: repeat({{ min(5, max(1, count($item['columns'] ?? []))) }}, minmax(0,1fr));">
                                            @forelse($item['columns'] ?? [] as $col)
                                                <div>
                                                    @if($col['heading'])<p class="font-display font-bold text-base text-gold-700 mb-2.5 tracking-wide">{{ $col['heading'] }}</p>@endif
                                                    <ul class="space-y-1.5">
                                                        @foreach($col['links'] as $l)
                                                            <li><a href="{{ $l['url'] }}" @if($l['new_tab']) target="_blank" rel="noopener" @endif class="text-sm text-ink-700/80 hover:text-gold-700">{{ $l['label'] }}</a></li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @empty
                                                <a href="{{ $item['url'] }}" class="text-sm text-gold-700">View {{ $item['label'] }}</a>
                                            @endforelse
                                        </div>
                                    </div>
                                @else
                                    {{-- Simple dropdown --}}
                                    <div x-show="o" x-cloak @click.outside="o=false" x-transition.opacity
                                         class="absolute left-0 top-full pt-3 z-50 min-w-48">
                                        <div class="rounded-lg border border-gold-100 bg-white shadow-lg py-2">
                                            <a href="{{ $item['url'] }}" @if($item['new_tab']) target="_blank" rel="noopener" @endif class="block px-4 py-2 hover:bg-gold-50 font-medium">All {{ $item['label'] }}</a>
                                            @foreach($item['children'] ?? [] as $child)
                                                <a href="{{ $child['url'] }}" @if($child['new_tab']) target="_blank" rel="noopener" @endif class="block px-4 py-2 hover:bg-gold-50 text-ink-700/80">{{ $child['label'] }}</a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    @endforeach
                    @if($ctaLabel = theme('menu_cta_label'))
                        <a href="{{ theme('menu_cta_link') ?: route('shop') }}" class="rounded-full bg-gold-600 text-white px-4 py-1.5 hover:bg-gold-700">{{ $ctaLabel }}</a>
                    @endif
                </nav>

                <div class="flex items-center gap-1 ml-auto">
                    {{-- Mobile search icon (toggles the bar below) --}}
                    @if(theme('menu_show_search', true))
                        <button type="button" @click="msearch = !msearch" class="md:hidden p-2 hover:text-gold-700" aria-label="Search">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                        </button>
                    @endif
                    @if(theme('menu_show_search', true))
                    <div class="hidden lg:block relative" x-data="searchBox()" @click.outside="open=false">
                        <form action="{{ route('shop') }}" method="GET">
                            <input name="q" x-model="q" @input="onInput()" @focus="q.length>=2 && (open=true)"
                                   value="{{ request('q') }}" placeholder="Search jewelry…" class="input py-1.5 w-44" autocomplete="off">
                        </form>
                        <div x-show="open && results.length" x-cloak x-transition
                             class="absolute right-0 mt-1 w-80 max-h-96 overflow-y-auto rounded-xl border border-ink-100 bg-white shadow-xl z-50 p-2">
                            <template x-for="r in results" :key="r.url">
                                <a :href="r.url" class="flex items-center gap-3 rounded-lg p-2 hover:bg-gold-50">
                                    <span class="w-10 h-10 rounded bg-gold-100 overflow-hidden shrink-0">
                                        <template x-if="r.thumb"><img :src="r.thumb" class="w-full h-full object-cover" alt=""></template>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm truncate" x-text="r.name"></span>
                                        <span class="block text-xs text-gold-700" x-text="r.price"></span>
                                    </span>
                                </a>
                            </template>
                        </div>
                        <div x-show="open && !results.length && !loading && q.length>=2" x-cloak class="absolute right-0 mt-1 w-80 rounded-xl border border-ink-100 bg-white shadow-xl z-50 p-3 text-sm text-ink-700/60">No matches — press Enter to search all.</div>
                    </div>
                    @endif
                    @auth('customer')
                        <a href="{{ route('account') }}" class="p-2 hover:text-gold-700" title="My account">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a8.25 8.25 0 0115 0"/></svg>
                        </a>
                    @else
                        <a href="{{ route('customer.login') }}" class="p-2 hover:text-gold-700" title="Login">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a8.25 8.25 0 0115 0"/></svg>
                        </a>
                    @endauth
                    <a href="{{ route('cart') }}" @click.prevent="$store.cart.openDrawer()" class="relative p-2 hover:text-gold-700" title="Cart">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"/></svg>
                        <span x-show="$store.cart.count > 0" x-cloak class="absolute -top-0.5 -right-0.5 badge bg-gold-600 text-white px-1.5 min-w-5 justify-center" x-text="$store.cart.count">{{ $cartCount ?? '' }}</span>
                    </a>
                </div>
            </div>

            {{-- Mobile collapsible search bar --}}
            @if(theme('menu_show_search', true))
            <div x-show="msearch" x-cloak class="md:hidden pb-3" x-data="searchBox()" @click.outside="msearch=false">
                <form action="{{ route('shop') }}" method="GET">
                    <input name="q" x-model="q" @input="onInput()" placeholder="Search jewelry…" class="input py-2 w-full" autocomplete="off" x-ref="msearchInput">
                </form>
                <div x-show="open && results.length" x-cloak class="mt-1 max-h-80 overflow-y-auto rounded-xl border border-ink-100 bg-white shadow-xl p-2">
                    <template x-for="r in results" :key="r.url">
                        <a :href="r.url" class="flex items-center gap-3 rounded-lg p-2 hover:bg-gold-50">
                            <span class="w-9 h-9 rounded bg-gold-100 overflow-hidden shrink-0"><template x-if="r.thumb"><img :src="r.thumb" class="w-full h-full object-cover" alt=""></template></span>
                            <span class="min-w-0 flex-1"><span class="block text-sm truncate" x-text="r.name"></span><span class="block text-xs text-gold-700" x-text="r.price"></span></span>
                        </a>
                    </template>
                </div>
            </div>
            @endif

            <div x-show="open" x-cloak class="md:hidden pb-4 space-y-1">
                @foreach($siteMenu ?? [] as $item)
                    @php $mtype = $item['type'] ?? (! empty($item['columns']) ? 'mega' : (! empty($item['children']) ? 'dropdown' : 'link')); @endphp
                    @if($mtype === 'link')
                        <a href="{{ $item['url'] }}" @if($item['new_tab']) target="_blank" rel="noopener" @endif class="flex items-center gap-2 py-2 border-b border-gold-100">
                            {{ $item['label'] }}
                            @if($item['badge'] ?? false)<span class="badge bg-gold-600 text-white text-[9px]">{{ $item['badge'] }}</span>@endif
                        </a>
                    @else
                        {{-- Parent is an accordion toggle (no navigation), per spec --}}
                        <div x-data="{ sub: false }" class="border-b border-gold-100">
                            <button type="button" @click="sub=!sub" class="w-full flex items-center justify-between py-2 text-left">
                                <span class="flex items-center gap-2">{{ $item['label'] }}@if($item['badge'] ?? false)<span class="badge bg-gold-600 text-white text-[9px]">{{ $item['badge'] }}</span>@endif</span>
                                <svg class="w-4 h-4 transition" :class="sub && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="sub" x-cloak class="pb-2 pl-3 space-y-1">
                                @if($mtype === 'mega')
                                    @foreach($item['columns'] ?? [] as $col)
                                        @if($col['heading'])<p class="pt-1.5 text-sm font-bold text-gold-700 uppercase tracking-wide">{{ $col['heading'] }}</p>@endif
                                        @foreach($col['links'] as $l)
                                            <a href="{{ $l['url'] }}" @if($l['new_tab']) target="_blank" rel="noopener" @endif class="block py-1.5 text-sm text-ink-700/80">{{ $l['label'] }}</a>
                                        @endforeach
                                    @endforeach
                                @else
                                    @foreach($item['children'] ?? [] as $child)
                                        <a href="{{ $child['url'] }}" @if($child['new_tab']) target="_blank" rel="noopener" @endif class="block py-1.5 text-sm text-ink-700/80">{{ $child['label'] }}</a>
                                    @endforeach
                                @endif
                                @if($item['view_all_mobile'] ?? false)
                                    <a href="{{ $item['url'] }}" @if($item['new_tab']) target="_blank" rel="noopener" @endif class="block py-1.5 text-sm font-medium text-gold-700">View all {{ $item['label'] }} →</a>
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach
                @if($ctaLabel = theme('menu_cta_label'))
                    <a href="{{ theme('menu_cta_link') ?: route('shop') }}" class="block py-2 mt-1 text-gold-700 font-medium">{{ $ctaLabel }}</a>
                @endif
            </div>
        </div>
    </header>

    {{-- Mini-cart slide-over --}}
    <div x-data x-show="$store.cart.drawer" x-cloak class="fixed inset-0 z-[60]" style="display:none">
        <div class="absolute inset-0 bg-black/40" @click="$store.cart.drawer=false" x-transition.opacity></div>
        <div class="absolute right-0 top-0 h-full w-full max-w-sm bg-white shadow-2xl flex flex-col"
             x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full">
            <div class="flex items-center justify-between px-5 py-4 border-b border-ink-100">
                <h2 class="font-display text-lg font-semibold">Your cart (<span x-text="$store.cart.count"></span>)</h2>
                <button @click="$store.cart.drawer=false" class="p-1 text-ink-700/60 hover:text-ink-900 text-2xl leading-none">&times;</button>
            </div>
            <div class="flex-1 overflow-y-auto p-5 space-y-4">
                <template x-if="!$store.cart.items.length">
                    <p class="text-center text-ink-700/50 py-10">Your cart is empty.</p>
                </template>
                <template x-for="item in $store.cart.items" :key="item.key">
                    <div class="relative flex gap-3 group/ci">
                        <a :href="item.url" class="flex gap-3 flex-1 min-w-0 pr-6">
                            <span class="w-16 h-16 rounded-lg bg-gold-100 overflow-hidden shrink-0">
                                <template x-if="item.image"><img :src="item.image" class="w-full h-full object-cover" alt=""></template>
                            </span>
                            <span class="flex-1 min-w-0">
                                <span class="block text-sm font-medium truncate" x-text="item.name"></span>
                                <span class="block text-xs text-ink-700/50">Qty <span x-text="item.qty"></span></span>
                                <span class="block text-sm text-gold-700" x-text="item.price_text"></span>
                            </span>
                        </a>
                        <button type="button" @click="$store.cart.remove(item.key)"
                            class="absolute top-0 right-0 p-1 text-ink-700/40 hover:text-red-600 transition"
                            :title="'Remove ' + item.name" aria-label="Remove item">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </template>
            </div>
            <div class="border-t border-ink-100 p-5 space-y-3" x-show="$store.cart.items.length">
                <div class="flex justify-between text-sm"><span class="text-ink-700/70">Subtotal</span><span x-text="$store.cart.subtotalText"></span></div>

                {{-- Why you're saving --}}
                <template x-for="d in $store.cart.discountLines" :key="d.label">
                    <div class="flex justify-between text-sm text-green-700"><span x-text="d.label"></span><span x-text="'−' + d.amount_text"></span></div>
                </template>
                <div class="flex justify-between text-sm text-green-700" x-show="$store.cart.freeShipping">
                    <span>Free delivery</span><span>✓</span>
                </div>

                {{-- Almost-there nudges --}}
                <template x-for="h in $store.cart.hints" :key="h">
                    <div class="rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-3 py-2 text-xs" x-text="'🎁 ' + h"></div>
                </template>

                <a href="{{ route('cart') }}" class="btn-outline w-full block text-center">View cart</a>
                <a href="{{ route('checkout') }}" class="btn-primary w-full block text-center">Checkout</a>
            </div>
        </div>
    </div>

    {{-- Toast --}}
    <div x-data x-show="$store.cart.toastShow" x-cloak x-transition
         class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[70] rounded-full bg-ink-900 text-white px-5 py-2.5 text-sm shadow-lg" style="display:none">
        <span x-text="$store.cart.toastMsg"></span>
    </div>

    @if(session('success'))
        <div class="mx-auto max-w-7xl px-4 mt-4"><div class="rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-3 text-sm">{{ session('success') }}</div></div>
    @endif
    @if(session('error'))
        <div class="mx-auto max-w-7xl px-4 mt-4"><div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">{{ session('error') }}</div></div>
    @endif

    <main class="flex-1">
        @yield('content')
    </main>

    <footer class="mt-16 bg-ink-900 text-gold-100">
        <div class="mx-auto max-w-7xl px-4 py-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="font-display text-xl font-bold text-gold-300">{{ theme('footer_brand') ?: \App\Models\Setting::get('store_name', config('store.name')) }}</div>
                <p class="mt-3 text-sm text-gold-100/70">{{ theme('footer_about') ?: 'Handpicked jewelry, delivered across Bangladesh. Cash on delivery available.' }}</p>
                @php($fbUrl = theme('footer_facebook'))
                @php($igUrl = theme('footer_instagram'))
                @if($fbUrl || $igUrl)
                    <div class="mt-4 flex gap-3">
                        @if($fbUrl)<a href="{{ $fbUrl }}" target="_blank" rel="noopener" class="text-gold-100/70 hover:text-white" aria-label="Facebook"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987H7.898v-2.89h2.54V9.797c0-2.507 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z"/></svg></a>@endif
                        @if($igUrl)<a href="{{ $igUrl }}" target="_blank" rel="noopener" class="text-gold-100/70 hover:text-white" aria-label="Instagram"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg></a>@endif
                    </div>
                @endif
            </div>
            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gold-300">Shop</h3>
                <ul class="mt-3 space-y-2 text-sm text-gold-100/80">
                    @foreach($navCategories ?? [] as $cat)
                        <li><a href="{{ route('category.show', $cat) }}" class="hover:text-white">{{ $cat->name }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gold-300">Help</h3>
                <ul class="mt-3 space-y-2 text-sm text-gold-100/80">
                    <li><a href="{{ route('track') }}" class="hover:text-white">Track your order</a></li>
                    <li><a href="{{ route('cart') }}" class="hover:text-white">Cart</a></li>
                    @guest('customer')<li><a href="{{ route('customer.login') }}" class="hover:text-white">Login / Register</a></li>@endguest
                </ul>
            </div>
            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gold-300">Contact</h3>
                <ul class="mt-3 space-y-2 text-sm text-gold-100/80">
                    @if($p = \App\Models\Setting::get('store_phone', config('store.phone')))<li>📞 {{ $p }}</li>@endif
                    <li>✉️ {{ \App\Models\Setting::get('store_email', config('store.email')) }}</li>
                    @if($waNum = theme('whatsapp_number'))
                        <li><a href="https://wa.me/{{ preg_replace('/\D/', '', $waNum) }}" target="_blank" rel="noopener" class="hover:text-white inline-flex items-center gap-1">
                            <svg class="w-4 h-4 text-[#25D366]" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.978-1.607zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.247-.694.247-1.289.173-1.413z"/></svg>
                            WhatsApp</a></li>
                    @endif
                </ul>
            </div>
        </div>
        <div class="border-t border-white/10 py-4 text-center text-xs text-gold-100/50">
            {{ theme('footer_copyright') ?: '© '.date('Y').' '.config('store.name').'. All rights reserved.' }}
        </div>
    </footer>

    {{-- Floating contact stack: Call, Messenger, WhatsApp --}}
    @if(theme('show_call_button') || (theme('show_whatsapp_button') && theme('whatsapp_number')) || (theme('show_messenger_button') && theme('messenger_url')))
        <div class="fixed bottom-5 right-5 z-50 flex flex-col items-center gap-3">
            @if(theme('show_call_button') && ($callNum = \App\Models\Setting::get('store_phone', config('store.phone'))))
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $callNum) }}"
                   class="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-gold-600 text-white shadow-lg hover:scale-105 transition p-3.5"
                   title="Call us now">
                    <svg class="w-full h-full" fill="currentColor" viewBox="0 0 24 24"><path d="M6.62 10.79a15.05 15.05 0 006.59 6.59l2.2-2.2a1 1 0 011.02-.24 11.36 11.36 0 003.56.57 1 1 0 011 1V20a1 1 0 01-1 1A17 17 0 013 4a1 1 0 011-1h3.5a1 1 0 011 1 11.36 11.36 0 00.57 3.56 1 1 0 01-.24 1.02l-2.21 2.21z"/></svg>
                </a>
            @endif
            @if(theme('show_messenger_button') && ($msgUrl = theme('messenger_url')))
                <a href="{{ $msgUrl }}" target="_blank" rel="noopener"
                   class="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-[#0084FF] text-white shadow-lg hover:scale-105 transition p-3"
                   title="Message us">
                    <svg class="w-full h-full" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.19 5.44 3.14 7.17.16.14.26.35.27.57l.05 1.78c.02.57.6.94 1.12.71l1.99-.88c.17-.07.36-.09.54-.04 1.86.51 3.86.66 5.8.36C19.64 21.36 22 17.83 22 11.7 22 6.13 17.64 2 12 2zm6 7.46l-2.93 4.65a1.5 1.5 0 01-2.17.4l-2.33-1.75a.6.6 0 00-.72 0l-3.16 2.4c-.42.32-.97-.18-.69-.63l2.93-4.65a1.5 1.5 0 012.17-.4l2.33 1.75a.6.6 0 00.72 0l3.16-2.4c.42-.32.97.18.69.63z"/></svg>
                </a>
            @endif
            @if(theme('show_whatsapp_button') && ($waNum = theme('whatsapp_number')))
                <a href="https://wa.me/{{ preg_replace('/\D/', '', $waNum) }}" target="_blank" rel="noopener"
                   class="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-lg hover:scale-105 transition p-3"
                   title="Order on WhatsApp">
                    <svg class="w-full h-full" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.978-1.607zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.247-.694.247-1.289.173-1.413z"/></svg>
                </a>
            @endif
        </div>
    @endif
</body>
</html>
