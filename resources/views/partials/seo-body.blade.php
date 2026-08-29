{{--
    The pre-hydration shell: real HTML, rendered inside <div id="app">.

    Why this exists. The storefront's browsing pages are React over Inertia, so
    the document that came off the server was a JSON blob and an empty div —
    literally zero <h1> elements and zero <a href> on a product page. Everything
    a crawler is supposed to read (the heading, the copy, the price, the links
    to the other 130 pages) existed only after JavaScript ran.

    Google will render JavaScript, eventually and inconsistently. Bing largely
    will not, and the AI answer engines that increasingly sit between a shopper
    and a shop do not render at all. A site with no server-rendered links also
    has no crawlable link graph, so internal linking — the main thing that
    tells Google which of 100 products matter — was doing nothing.

    React replaces this the moment it mounts (createRoot().render() clears the
    container), so it is a genuine fallback and not hidden text: no cloaking,
    the crawler and a JS-less visitor see exactly the same thing. On a slow
    Bangladeshi mobile connection it also means the page shows its heading,
    price and links while the bundle is still downloading, instead of the blank
    white screen it showed before.

    Server-side rendering the real React tree would be better still, but it
    needs a Node process the shared host does not have. This is the honest
    version of that, at no runtime cost.
--}}
@php
    use Illuminate\Support\Str;

    $isProductPage = isset($product)
        && $product instanceof \App\Models\Product
        && request()->routeIs('product.show');

    $heading = $seoHeading ?? ($isProductPage ? $product->name : ($pageTitle ?? store_name()));

    // Top-level categories, cached — the crawlable spine of the site, present
    // on every page so no product is more than two hops from the homepage.
    $seoNav = \Illuminate\Support\Facades\Cache::remember('seo.nav.categories', 3600, function () {
        return \App\Models\Category::active()
            ->whereNull('parent_id')
            ->orderBy('position')
            ->get(['name', 'slug'])
            ->map(fn ($c) => ['name' => $c->name, 'url' => route('category.show', $c->slug)])
            ->all();
    });
@endphp
<div id="seo-shell" style="max-width:64rem;margin:0 auto;padding:2rem 1.25rem;font-family:var(--font-sans,system-ui,sans-serif);color:var(--color-ink-900,#2a1a00);line-height:1.6">
    <p style="font-size:.95rem;margin:0 0 1.25rem">
        <a href="{{ route('home') }}" style="color:inherit">{{ store_name() }}</a>
        @if($isProductPage && $product->category)
            <span aria-hidden="true"> › </span>
            <a href="{{ route('category.show', $product->category->slug) }}" style="color:inherit">{{ $product->category->name }}</a>
        @elseif(! request()->routeIs('home'))
            <span aria-hidden="true"> › </span>
            <a href="{{ route('shop') }}" style="color:inherit">Shop</a>
        @endif
    </p>

    <h1 style="font-family:var(--font-serif,Georgia,serif);font-size:1.9rem;font-weight:600;margin:0 0 .75rem">{{ $heading }}</h1>

    @if($isProductPage)
        <p style="font-size:1.35rem;font-weight:600;margin:0 0 .5rem">
            {{ config('store.currency_symbol', '৳') }}{{ number_format((float) $product->price) }}
            <span style="font-size:.85rem;font-weight:400">
                — {{ ($product->isAvailable() || $product->isPreorder()) ? 'In stock' : 'Out of stock' }},
                cash on delivery all over Bangladesh
            </span>
        </p>
        @if($body = plain_copy(strip_tags((string) ($product->description ?: $product->short_description))))
            <p style="margin:0 0 1rem;white-space:pre-line">{{ Str::limit($body, 1200) }}</p>
        @endif
    @else
        {{-- Only a page's own copy, never the meta description: repeating the
             description as body text is thin duplication, and on the homepage
             it printed the hero subtitle a second time. --}}
        @if(filled($seoIntro ?? null))
            <p style="margin:0 0 1.25rem">{{ Str::limit(trim(strip_tags((string) $seoIntro)), 2000) }}</p>
        @endif
    @endif

    @if(! empty($seoItems))
        <h2 style="font-family:var(--font-serif,Georgia,serif);font-size:1.25rem;margin:1.75rem 0 .75rem">
            {{ $isProductPage ? 'You may also like' : $heading }}
        </h2>
        <ul style="list-style:none;padding:0;margin:0 0 1.5rem;display:grid;gap:.4rem">
            @foreach($seoItems as $item)
                @continue(empty($item['url']))
                <li>
                    <a href="{{ $item['url'] }}" style="color:inherit">{{ $item['name'] ?? $item['url'] }}</a>
                    @if(! empty($item['price_text'])) — {{ $item['price_text'] }}@endif
                </li>
            @endforeach
        </ul>
    @endif

    @if($seoNav)
        <h2 style="font-family:var(--font-serif,Georgia,serif);font-size:1.1rem;margin:1.75rem 0 .5rem">Shop by category</h2>
        <ul style="list-style:none;padding:0;margin:0 0 1.5rem;display:flex;flex-wrap:wrap;gap:.35rem 1rem">
            @foreach($seoNav as $item)
                <li><a href="{{ $item['url'] }}" style="color:inherit">{{ $item['name'] }}</a></li>
            @endforeach
        </ul>
    @endif

    <ul style="list-style:none;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:.35rem 1rem;font-size:.9rem">
        <li><a href="{{ route('shop') }}" style="color:inherit">All jewelry</a></li>
        <li><a href="{{ route('best-sellers') }}" style="color:inherit">Best sellers</a></li>
        <li><a href="{{ route('page.about') }}" style="color:inherit">About us</a></li>
        <li><a href="{{ route('page.contact') }}" style="color:inherit">Contact</a></li>
        <li><a href="{{ route('page.refund') }}" style="color:inherit">Refund policy</a></li>
        <li><a href="{{ route('page.terms') }}" style="color:inherit">Terms</a></li>
        <li><a href="{{ route('page.privacy') }}" style="color:inherit">Privacy</a></li>
    </ul>
</div>
