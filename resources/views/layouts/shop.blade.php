<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('store.name')) — {{ config('store.name') }}</title>
    @hasSection('meta')@yield('meta')@endif
    @if($fav = theme_asset(theme('favicon')))<link rel="icon" href="{{ $fav }}">@endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>window.__cartCount = {{ $cartCount ?? 0 }};</script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        :root{
            --brand: {{ theme('primary') }};
            --brand-ink: {{ theme('accent') }};
            /* Full gold scale derived from the primary colour */
            --color-gold-50:  color-mix(in srgb, var(--brand) 8%,  white);
            --color-gold-100: color-mix(in srgb, var(--brand) 16%, white);
            --color-gold-200: color-mix(in srgb, var(--brand) 30%, white);
            --color-gold-300: color-mix(in srgb, var(--brand) 48%, white);
            --color-gold-400: color-mix(in srgb, var(--brand) 70%, white);
            --color-gold-500: color-mix(in srgb, var(--brand) 88%, white);
            --color-gold-600: var(--brand);
            --color-gold-700: color-mix(in srgb, var(--brand) 82%, black);
            --color-gold-800: color-mix(in srgb, var(--brand) 64%, black);
            --color-gold-900: color-mix(in srgb, var(--brand) 52%, black);
            /* Accent / text scale derived from the accent colour */
            --color-ink-900: var(--brand-ink);
            --color-ink-800: color-mix(in srgb, var(--brand-ink) 90%, white);
            --color-ink-700: color-mix(in srgb, var(--brand-ink) 78%, white);
        }
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
    @php($announce = theme())
    @php($announceMsgs = array_values(array_filter((array) ($announce['announcement_messages'] ?? []))))
    @if($announce['announcement_enabled'] && !empty($announceMsgs))
        @php($announceSpeed = max(10, count($announceMsgs) * (int) ($announce['announcement_speed'] ?? 6)))
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

    <header class="sticky top-0 z-40 bg-gold-50/95 backdrop-blur border-b border-gold-200" x-data="{ open: false }">
        <div class="mx-auto max-w-7xl px-4">
            <div class="flex h-16 items-center gap-3">
                {{-- Logo (left on all screens) --}}
                <a href="{{ route('home') }}" class="shrink-0">
                    @if($logo = theme_asset(theme('logo')))
                        <img src="{{ $logo }}" alt="{{ config('store.name') }}" class="h-9 w-auto">
                    @else
                        <span class="font-display text-xl sm:text-2xl font-bold tracking-wide text-gold-700">{{ config('store.name') }}</span>
                    @endif
                </a>

                {{-- Mobile search (middle) --}}
                @if(theme('menu_show_search', true))
                <div class="flex-1 md:hidden relative" x-data="searchBox()" @click.outside="open=false">
                    <form action="{{ route('shop') }}" method="GET">
                        <input name="q" x-model="q" @input="onInput()" placeholder="Search jewelry…" class="input py-2 w-full" autocomplete="off">
                    </form>
                    <div x-show="open && results.length" x-cloak class="absolute left-0 right-0 mt-1 max-h-80 overflow-y-auto rounded-xl border border-ink-100 bg-white shadow-xl z-50 p-2">
                        <template x-for="r in results" :key="r.url">
                            <a :href="r.url" class="flex items-center gap-3 rounded-lg p-2 hover:bg-gold-50">
                                <span class="w-9 h-9 rounded bg-gold-100 overflow-hidden shrink-0"><template x-if="r.thumb"><img :src="r.thumb" class="w-full h-full object-cover" alt=""></template></span>
                                <span class="min-w-0 flex-1"><span class="block text-sm truncate" x-text="r.name"></span><span class="block text-xs text-gold-700" x-text="r.price"></span></span>
                            </a>
                        </template>
                    </div>
                </div>
                @endif

                @php($menuTrigger = theme('menu_desktop_trigger', 'hover'))
                <nav class="hidden md:flex items-center gap-6 text-sm font-medium">
                    @foreach($siteMenu ?? [] as $item)
                        @if(empty($item['children']))
                            <a href="{{ $item['url'] }}" @if($item['new_tab']) target="_blank" rel="noopener" @endif class="hover:text-gold-700">{{ $item['label'] }}</a>
                        @else
                            <div class="relative" x-data="{ o: false }"
                                 @if($menuTrigger === 'hover') @mouseenter="o=true" @mouseleave="o=false" @endif>
                                <button type="button" @if($menuTrigger === 'click') @click="o=!o" @endif class="flex items-center gap-1 hover:text-gold-700">
                                    {{ $item['label'] }}
                                    <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                <div x-show="o" x-cloak @click.outside="o=false" x-transition.opacity
                                     class="absolute left-0 top-full pt-3 z-50 min-w-48">
                                    <div class="rounded-lg border border-gold-100 bg-white shadow-lg py-2">
                                        <a href="{{ $item['url'] }}" @if($item['new_tab']) target="_blank" rel="noopener" @endif class="block px-4 py-2 hover:bg-gold-50 font-medium">All {{ $item['label'] }}</a>
                                        @foreach($item['children'] as $child)
                                            <a href="{{ $child['url'] }}" @if($child['new_tab']) target="_blank" rel="noopener" @endif class="block px-4 py-2 hover:bg-gold-50 text-ink-700/80">{{ $child['label'] }}</a>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                    @if($ctaLabel = theme('menu_cta_label'))
                        <a href="{{ theme('menu_cta_link') ?: route('shop') }}" class="rounded-full bg-gold-600 text-white px-4 py-1.5 hover:bg-gold-700">{{ $ctaLabel }}</a>
                    @endif
                </nav>

                <div class="flex items-center gap-1 ml-auto">
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
                    {{-- Hamburger (mobile, right) --}}
                    <button @click="open = !open" class="md:hidden p-2 -mr-2" aria-label="Menu">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/></svg>
                    </button>
                </div>
            </div>

            <div x-show="open" x-cloak class="md:hidden pb-4 space-y-1">
                @foreach($siteMenu ?? [] as $item)
                    @if(empty($item['children']))
                        <a href="{{ $item['url'] }}" @if($item['new_tab']) target="_blank" rel="noopener" @endif class="block py-2 border-b border-gold-100">{{ $item['label'] }}</a>
                    @else
                        <div x-data="{ sub: false }" class="border-b border-gold-100">
                            <button type="button" @click="sub=!sub" class="w-full flex items-center justify-between py-2 text-left">
                                <span>{{ $item['label'] }}</span>
                                <svg class="w-4 h-4 transition" :class="sub && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="sub" x-cloak class="pb-2 pl-3 space-y-1">
                                <a href="{{ $item['url'] }}" @if($item['new_tab']) target="_blank" rel="noopener" @endif class="block py-1.5 text-sm text-ink-700/80">All {{ $item['label'] }}</a>
                                @foreach($item['children'] as $child)
                                    <a href="{{ $child['url'] }}" @if($child['new_tab']) target="_blank" rel="noopener" @endif class="block py-1.5 text-sm text-ink-700/80">{{ $child['label'] }}</a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
                @if($ctaLabel = theme('menu_cta_label'))
                    <a href="{{ theme('menu_cta_link') ?: route('shop') }}" class="block py-2 mt-1 text-gold-700 font-medium">{{ $ctaLabel }}</a>
                @endif
                @if(theme('menu_show_search', true))
                <form action="{{ route('shop') }}" method="GET" class="pt-3">
                    <input name="q" value="{{ request('q') }}" placeholder="Search jewelry…" class="input">
                </form>
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
                    <a :href="item.url" class="flex gap-3">
                        <span class="w-16 h-16 rounded-lg bg-gold-100 overflow-hidden shrink-0">
                            <template x-if="item.image"><img :src="item.image" class="w-full h-full object-cover" alt=""></template>
                        </span>
                        <span class="flex-1 min-w-0">
                            <span class="block text-sm font-medium truncate" x-text="item.name"></span>
                            <span class="block text-xs text-ink-700/50">Qty <span x-text="item.qty"></span></span>
                            <span class="block text-sm text-gold-700" x-text="item.price_text"></span>
                        </span>
                    </a>
                </template>
            </div>
            <div class="border-t border-ink-100 p-5 space-y-3" x-show="$store.cart.items.length">
                <div class="flex justify-between font-semibold"><span>Subtotal</span><span x-text="$store.cart.subtotalText"></span></div>
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
                <div class="font-display text-xl font-bold text-gold-300">{{ config('store.name') }}</div>
                <p class="mt-3 text-sm text-gold-100/70">Handpicked jewelry, delivered across Bangladesh. Cash on delivery available.</p>
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
            © {{ date('Y') }} {{ config('store.name') }}. All rights reserved.
        </div>
    </footer>

    @if($wa = theme('whatsapp_number'))
        <a href="https://wa.me/{{ preg_replace('/\D/', '', $wa) }}" target="_blank" rel="noopener"
           class="fixed bottom-5 right-5 z-50 flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-lg hover:scale-105 transition"
           title="Order on WhatsApp">
            <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.978-1.607zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.247-.694.247-1.289.173-1.413z"/></svg>
        </a>
    @endif
</body>
</html>
