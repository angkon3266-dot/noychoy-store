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

@elseif($type === 'richtext')
    @if(filled($block['html'] ?? null))
        <section class="mx-auto max-w-4xl px-4 py-8 prose prose-sm sm:prose">{!! $block['html'] !!}</section>
    @endif
@endif
