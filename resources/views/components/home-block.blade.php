@props(['block'])
@php
    $type = $block['type'] ?? 'banner';
    $img = function ($p) {
        $p = (string) ($p ?? '');
        return $p === '' ? null : (\Illuminate\Support\Str::startsWith($p, ['http', '/']) ? $p : \Illuminate\Support\Facades\Storage::disk('public')->url($p));
    };
@endphp

@if($type === 'banner')
    @php
        $images = collect($block['images'] ?? [])->filter(fn ($i) => filled($i['image'] ?? null))->values();
        $cols = ($block['layout'] ?? 'single') === 'single' ? 1 : (($block['layout'] ?? '') === 'dual' ? 2 : 3);
    @endphp
    @if($images->isNotEmpty())
        <section class="mx-auto max-w-7xl px-4 py-6">
            <div class="grid gap-4" style="grid-template-columns: repeat({{ $cols }}, minmax(0,1fr))">
                @foreach($images as $im)
                    <a href="{{ $im['link'] ?: '#' }}" class="block overflow-hidden rounded-2xl group">
                        <img src="{{ $img($im['image']) }}" alt="" class="w-full h-full object-cover transition duration-700 group-hover:scale-105">
                    </a>
                @endforeach
            </div>
        </section>
    @endif

@elseif($type === 'product_carousel')
    @php $products = $block['products'] ?? collect(); @endphp
    @if($products->isNotEmpty())
        <x-section-heading :title="$block['title'] ?? 'Products'" :link="$block['view_all_link'] ?? route('shop')" linkLabel="View All" />
        <x-carousel class="mx-auto max-w-7xl px-4 pb-10">
            @foreach($products as $product)
                <div class="snap-start shrink-0 w-52 sm:w-60"><x-product-card :product="$product" /></div>
            @endforeach
        </x-carousel>
    @endif

@elseif($type === 'banner_carousel')
    @php $products = $block['products'] ?? collect(); $banner = $block['banner'] ?? []; @endphp
    <section class="mx-auto max-w-7xl px-4 py-8">
        @if(($block['title'] ?? '') !== '')<h2 class="font-display text-2xl sm:text-3xl text-ink-900 mb-4">{{ $block['title'] }}</h2>@endif
        <div class="grid md:grid-cols-[300px_1fr] gap-5 items-stretch">
            @if(filled($banner['image'] ?? null))
                <a href="{{ $banner['link'] ?? '#' }}" class="relative block overflow-hidden rounded-2xl group min-h-[220px]">
                    <img src="{{ $img($banner['image']) }}" alt="" class="absolute inset-0 w-full h-full object-cover transition duration-700 group-hover:scale-105">
                </a>
            @endif
            <div class="min-w-0">
                @if($products->isNotEmpty())
                    <x-carousel>
                        @foreach($products as $product)
                            <div class="snap-start shrink-0 w-48 sm:w-56"><x-product-card :product="$product" /></div>
                        @endforeach
                    </x-carousel>
                @endif
            </div>
        </div>
    </section>

@elseif($type === 'video')
    @php $videos = collect($block['videos'] ?? []); @endphp
    @if($videos->isNotEmpty())
        @if(($block['title'] ?? '') !== '')<x-section-heading :title="$block['title']" />@endif
        <section class="mx-auto max-w-7xl px-4 pb-12 grid gap-6 md:grid-cols-2">
            @foreach($videos as $v)
                <div>
                    @if($v['title'])<h3 class="text-sm font-semibold uppercase tracking-wide text-ink-700/70 mb-3">{{ $v['title'] }}</h3>@endif
                    <div class="aspect-video overflow-hidden rounded-xl bg-ink-900">
                        @if($v['meta']['type'] === 'file')
                            <video src="{{ $v['meta']['src'] }}" controls preload="metadata" class="w-full h-full object-cover"></video>
                        @else
                            <iframe src="{{ $v['meta']['embed'] }}" title="{{ $v['title'] }}" class="w-full h-full" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                        @endif
                    </div>
                </div>
            @endforeach
        </section>
    @endif

@elseif($type === 'cta_banner')
    @php
        $cta = $block['cta'] ?? [];
        $ctaImg = $img($cta['image'] ?? null);
        $align = in_array($cta['align'] ?? 'center', ['left', 'center', 'right'], true) ? $cta['align'] : 'center';
        $alignCls = ['left' => 'text-left', 'right' => 'text-right ml-auto', 'center' => 'text-center mx-auto'][$align];
        $height = ['sm' => 'min-h-[280px]', 'md' => 'min-h-[420px]', 'lg' => 'min-h-[560px]'][$cta['height'] ?? 'md'] ?? 'min-h-[420px]';
    @endphp
    @if($ctaImg || filled($cta['heading'] ?? null))
    <section class="relative w-full {{ $height }} flex overflow-hidden my-6">
        @if($ctaImg)<img src="{{ $ctaImg }}" alt="" class="absolute inset-0 w-full h-full object-cover">@endif
        <div class="absolute inset-0 bg-black/40"></div>
        <div class="relative z-10 mx-auto max-w-7xl w-full px-6 py-12 flex flex-col justify-center">
            <div class="max-w-xl {{ $alignCls }}">
                @if(filled($cta['eyebrow'] ?? null))<p class="uppercase tracking-[0.3em] text-xs text-white/80 mb-3">{{ $cta['eyebrow'] }}</p>@endif
                @if(filled($cta['heading'] ?? null))<h2 class="font-display text-3xl sm:text-5xl font-semibold text-white leading-tight">{{ $cta['heading'] }}</h2>@endif
                @if(filled($cta['subheading'] ?? null))<p class="mt-4 text-white/85 text-lg">{{ $cta['subheading'] }}</p>@endif
                @if(filled($cta['button_text'] ?? null))
                    <div class="mt-7">
                        <a href="{{ $cta['button_link'] ?: route('shop') }}" class="inline-flex items-center gap-2 rounded-full bg-white text-ink-900 px-8 py-3.5 text-sm tracking-wide hover:bg-gold-100 transition">{{ $cta['button_text'] }}</a>
                    </div>
                @endif
            </div>
        </div>
    </section>
    @endif

@elseif($type === 'reviews')
    @php
        $reviews = collect($block['reviews'] ?? []);
        // Sample testimonials shown until the admin picks real reviews.
        $placeholder = $reviews->isEmpty();
        $items = $placeholder ? collect([
            ['author' => 'Taslima S.', 'meta' => 'Dhaka', 'rating' => 5, 'quote' => 'The earrings arrived beautifully packaged. The quality is amazing for this price — my friends thought I spent ten times more!'],
            ['author' => 'Rafiq H.', 'meta' => 'Chattogram', 'rating' => 5, 'quote' => 'Ordered a ring for my wife. She absolutely loves it. The cash on delivery option made it so easy.'],
            ['author' => 'Nusrat A.', 'meta' => 'Sylhet', 'rating' => 5, 'quote' => 'I\'ve ordered three times now and every piece has been perfect. The bracelets are my favorite — so elegant and well-made.'],
        ]) : $reviews->map(fn ($r) => [
            'author' => $r->author_name,
            'meta' => $r->product?->name,
            'link' => $r->product ? route('product.show', $r->product->slug) : null,
            'rating' => (int) $r->rating,
            'quote' => $r->body ?: $r->title,
        ]);
    @endphp
    <section class="py-14 bg-gold-50/60">
        <div class="mx-auto max-w-7xl px-4">
            <div class="text-center mb-10">
                <p class="uppercase tracking-[0.3em] text-xs text-gold-600 mb-3">Customer love</p>
                <h2 class="font-display text-3xl sm:text-4xl text-ink-900">{{ ($block['title'] ?? '') !== '' ? $block['title'] : "What they're saying" }}</h2>
            </div>
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($items as $t)
                    <div class="rounded-2xl bg-white border border-ink-100 p-6 shadow-sm flex flex-col">
                        <div class="text-gold-500 tracking-wide mb-3" aria-label="{{ $t['rating'] }} star review">{{ str_repeat('★', max(1, min(5, $t['rating']))) }}</div>
                        <p class="font-display italic text-ink-800 leading-relaxed flex-1">&ldquo;{{ $t['quote'] }}&rdquo;</p>
                        <div class="flex items-center gap-3 mt-5">
                            <div class="w-10 h-10 rounded-full bg-gold-100 text-gold-700 text-xs font-semibold flex items-center justify-center shrink-0">
                                {{ collect(explode(' ', trim($t['author'])))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('') }}
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink-900">{{ $t['author'] }}</p>
                                @if(filled($t['meta'] ?? null))
                                    @if(filled($t['link'] ?? null))
                                        <a href="{{ $t['link'] }}" class="text-xs text-ink-700/50 hover:text-gold-700 truncate block">{{ $t['meta'] }}</a>
                                    @else
                                        <p class="text-xs text-ink-700/50 truncate">{{ $t['meta'] }}</p>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

@elseif($type === 'hero_cta')
    {{-- Landing hero: big headline, sub, two CTAs, optional background image. --}}
    @php
        $h = $block['hero'] ?? [];
        $bg = $img($h['image'] ?? null);
        $dark = (bool) ($h['dark'] ?? true);
    @endphp
    <section class="relative overflow-hidden {{ $bg ? '' : 'bg-gold-50' }}">
        @if($bg)<img src="{{ $bg }}" alt="" class="absolute inset-0 w-full h-full object-cover">@endif
        @if($bg)<div class="absolute inset-0 {{ $dark ? 'bg-black/50' : 'bg-white/60' }}"></div>@endif
        <div class="relative mx-auto max-w-5xl px-5 py-20 sm:py-28 text-center">
            @if(filled($h['eyebrow'] ?? null))
                <p class="uppercase tracking-[0.35em] text-xs mb-4 {{ $bg && $dark ? 'text-white/80' : 'text-gold-600' }}">{{ $h['eyebrow'] }}</p>
            @endif
            <h1 class="font-display text-4xl sm:text-6xl font-semibold leading-tight {{ $bg && $dark ? 'text-white' : 'text-ink-900' }}">
                {{ $h['heading'] ?? '' }}
            </h1>
            @if(filled($h['subheading'] ?? null))
                <p class="mt-5 text-lg max-w-2xl mx-auto {{ $bg && $dark ? 'text-white/85' : 'text-ink-700/75' }}">{{ $h['subheading'] }}</p>
            @endif
            <div class="mt-9 flex flex-wrap gap-3 justify-center">
                @if(filled($h['cta_text'] ?? null))
                    <a href="{{ $h['cta_link'] ?: '#buy' }}" class="inline-flex items-center rounded-full bg-gold-600 text-white px-9 py-4 text-sm font-medium tracking-wide hover:bg-gold-700 transition shadow-lg hover:shadow-xl hover:-translate-y-0.5 duration-300">{{ $h['cta_text'] }}</a>
                @endif
                @if(filled($h['cta2_text'] ?? null))
                    <a href="{{ $h['cta2_link'] ?: '#' }}" class="inline-flex items-center rounded-full px-9 py-4 text-sm tracking-wide transition border {{ $bg && $dark ? 'border-white/70 text-white hover:bg-white/10' : 'border-gold-300 text-gold-800 hover:bg-gold-100' }}">{{ $h['cta2_text'] }}</a>
                @endif
            </div>
            @if(filled($h['note'] ?? null))
                <p class="mt-5 text-xs {{ $bg && $dark ? 'text-white/70' : 'text-ink-700/55' }}">{{ $h['note'] }}</p>
            @endif
        </div>
    </section>

@elseif($type === 'benefits')
    @php $items = collect($block['benefits'] ?? [])->filter(fn ($b) => filled($b['title'] ?? null))->values(); @endphp
    @if($items->isNotEmpty())
        <section class="mx-auto max-w-6xl px-4 py-14">
            @if(($block['title'] ?? '') !== '')
                <h2 class="font-display text-3xl text-center mb-10">{{ $block['title'] }}</h2>
            @endif
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-{{ min(4, max(2, $items->count())) }}">
                @foreach($items as $b)
                    <div class="text-center px-4">
                        <div class="text-3xl mb-3">{{ $b['icon'] ?? '✨' }}</div>
                        <h3 class="font-semibold mb-1.5">{{ $b['title'] }}</h3>
                        @if(filled($b['text'] ?? null))<p class="text-sm text-ink-700/70 leading-relaxed">{{ $b['text'] }}</p>@endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

@elseif($type === 'countdown')
    @php
        $c = $block['countdown'] ?? [];
        $endsAt = filled($c['ends_at'] ?? null) ? \Illuminate\Support\Carbon::parse($c['ends_at']) : null;
    @endphp
    @if($endsAt && $endsAt->isFuture())
        <section class="bg-ink-900 text-white py-10" x-data="countdownBox({{ $endsAt->getTimestamp() }})" x-init="start()">
            <div class="mx-auto max-w-4xl px-4 text-center">
                @if(filled($c['title'] ?? null))<p class="font-display text-2xl sm:text-3xl mb-5">{{ $c['title'] }}</p>@endif
                <div class="flex justify-center gap-3 sm:gap-5">
                    <template x-for="u in units" :key="u.label">
                        <div class="min-w-[64px] rounded-xl bg-white/10 px-3 py-3">
                            <div class="text-2xl sm:text-3xl font-semibold tabular-nums" x-text="String(u.value).padStart(2,'0')"></div>
                            <div class="text-[10px] uppercase tracking-widest text-white/60 mt-1" x-text="u.label"></div>
                        </div>
                    </template>
                </div>
                @if(filled($c['cta_text'] ?? null))
                    <a href="{{ $c['cta_link'] ?: '#buy' }}" class="inline-flex mt-7 rounded-full bg-gold-500 text-ink-900 px-8 py-3.5 text-sm font-medium hover:bg-gold-400 transition">{{ $c['cta_text'] }}</a>
                @endif
            </div>
        </section>
    @endif

@elseif($type === 'buy_box')
    @php $products = $block['products'] ?? collect(); @endphp
    @if($products->isNotEmpty())
        <section id="buy" class="mx-auto max-w-6xl px-4 py-14">
            @if(($block['title'] ?? '') !== '')
                <h2 class="font-display text-3xl text-center mb-10">{{ $block['title'] }}</h2>
            @endif
            <div class="grid gap-6 {{ $products->count() === 1 ? 'max-w-md mx-auto' : 'sm:grid-cols-2 lg:grid-cols-3' }}">
                @foreach($products as $product)
                    <div class="card p-4 text-center">
                        <a href="{{ route('product.show', $product) }}" class="block overflow-hidden rounded-xl bg-gold-100 aspect-square">
                            @if($product->thumbnail)
                                <img src="{{ $product->thumbnail }}" alt="{{ $product->name }}" class="w-full h-full object-cover hover:scale-105 transition duration-700" loading="lazy">
                            @endif
                        </a>
                        <h3 class="font-medium mt-4">{{ $product->name }}</h3>
                        <p class="text-gold-700 text-lg font-semibold mt-1">
                            {{ money($product->price) }}
                            @if($product->compare_at_price > $product->price)
                                <span class="text-ink-400 line-through text-sm ml-1">{{ money($product->compare_at_price) }}</span>
                            @endif
                        </p>
                        <form action="{{ route('cart.add', $product->slug) }}" method="POST" class="mt-4"
                              @submit.prevent="$store.cart.add($event.target)">
                            @csrf
                            <input type="hidden" name="qty" value="1">
                            <button class="btn-primary w-full">{{ $block['cta_label'] ?? 'Add to cart' }}</button>
                        </form>
                        <p class="text-xs text-ink-700/50 mt-2">Cash on delivery · pay when it arrives</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

@elseif($type === 'faq')
    @php $items = collect($block['faqs'] ?? [])->filter(fn ($f) => filled($f['q'] ?? null))->values(); @endphp
    @if($items->isNotEmpty())
        <section class="mx-auto max-w-3xl px-4 py-14">
            <h2 class="font-display text-3xl text-center mb-8">{{ ($block['title'] ?? '') !== '' ? $block['title'] : 'Questions, answered' }}</h2>
            <div class="divide-y divide-ink-100 border-y border-ink-100">
                @foreach($items as $f)
                    <details class="group py-4" x-data>
                        <summary class="flex items-center justify-between cursor-pointer font-medium list-none">
                            <span>{{ $f['q'] }}</span>
                            <svg class="w-5 h-5 shrink-0 text-ink-700/40 transition-transform duration-300 group-open:rotate-45" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 4v16m8-8H4"/></svg>
                        </summary>
                        @if(filled($f['a'] ?? null))<p class="text-sm text-ink-700/75 mt-3 leading-relaxed">{{ $f['a'] }}</p>@endif
                    </details>
                @endforeach
            </div>
        </section>
    @endif

@elseif($type === 'sticky_cta')
    @php $s = $block['sticky'] ?? []; @endphp
    @if(filled($s['text'] ?? null) || filled($s['button'] ?? null))
        <div x-data="{ show: false }"
             x-init="window.addEventListener('scroll', () => show = window.scrollY > 500)"
             x-show="show" x-cloak x-transition.opacity
             class="fixed inset-x-0 bottom-0 z-[60] border-t border-ink-100 bg-white/95 backdrop-blur shadow-[0_-4px_20px_rgba(0,0,0,0.08)]">
            <div class="mx-auto max-w-5xl px-4 py-3 flex items-center gap-4">
                <p class="text-sm font-medium flex-1 min-w-0 truncate">{{ $s['text'] ?? '' }}</p>
                <a href="{{ $s['link'] ?: '#buy' }}" class="btn-primary whitespace-nowrap shrink-0">{{ $s['button'] ?: 'Order now' }}</a>
            </div>
        </div>
        {{-- Spacer so the bar never covers the last of the page content. --}}
        <div class="h-16"></div>
    @endif

@elseif($type === 'richtext')
    @if(filled($block['html'] ?? null))
        <section class="mx-auto max-w-4xl px-4 py-8 prose prose-sm sm:prose">{!! $block['html'] !!}</section>
    @endif
@endif
