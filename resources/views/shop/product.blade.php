@extends('layouts.shop')
@section('title', $product->meta_title ?: $product->name)
@section('meta')
    <meta name="description" content="{{ $product->meta_description ?: \Illuminate\Support\Str::limit(strip_tags($product->short_description), 150) }}">
@endsection

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8"
     x-data="{
        img: '{{ $product->images->first()?->url ?? '' }}',
        qty: 1,
        variant: {{ $product->has_variants ? 'null' : "'none'" }},
        variants: {{ Js::from($product->variants->mapWithKeys(fn($v) => [$v->id => ['label' => $v->attributes['Option'] ?? $v->label, 'price' => $v->effective_price, 'stock' => $v->stock_quantity]])) }}
     }">
    <nav class="text-sm text-ink-700/60 mb-6">
        <a href="{{ route('home') }}" class="hover:text-gold-700">Home</a> /
        @if($product->category)<a href="{{ route('category.show', $product->category) }}" class="hover:text-gold-700">{{ $product->category->name }}</a> /@endif
        <span class="text-ink-800">{{ $product->name }}</span>
    </nav>

    <div class="grid lg:grid-cols-2 gap-10">
        <!-- Gallery -->
        <div>
            <div class="aspect-square overflow-hidden rounded-2xl bg-gold-100">
                @if($product->images->isNotEmpty())
                    <img :src="img" alt="{{ $product->name }}" class="h-full w-full object-cover">
                @else
                    <div class="flex h-full items-center justify-center text-gold-300">No image</div>
                @endif
            </div>
            @if($product->images->count() > 1)
                <div class="mt-4 grid grid-cols-5 gap-3">
                    @foreach($product->images as $image)
                        <button @click="img='{{ $image->url }}'" class="aspect-square overflow-hidden rounded-lg bg-gold-100 ring-2 ring-transparent" :class="img==='{{ $image->url }}' && 'ring-gold-500'">
                            <img src="{{ $image->url }}" alt="" class="h-full w-full object-cover">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Info -->
        <div>
            <h1 class="font-display text-3xl font-semibold">{{ $product->name }}</h1>
            @if($product->sku)<p class="text-xs text-ink-700/50 mt-1">SKU: {{ $product->sku }}</p>@endif

            <div class="mt-4 flex items-baseline gap-3">
                <template x-if="variant && variant!=='none'">
                    <span class="text-2xl font-semibold text-gold-700" x-text="'৳'+ Number(variants[variant].price).toLocaleString()"></span>
                </template>
                <template x-if="!variant || variant==='none'">
                    <span class="text-2xl font-semibold text-gold-700">{{ money($product->price) }}</span>
                </template>
                @if($product->is_on_sale)
                    <span class="text-ink-400 line-through">{{ money($product->compare_at_price) }}</span>
                    <span class="badge bg-red-100 text-red-700">Save {{ $product->discount_percent }}%</span>
                @endif
            </div>

            @if($product->short_description)
                <p class="mt-4 text-ink-700/80">{{ $product->short_description }}</p>
            @endif

            <form action="{{ route('cart.add', $product) }}" method="POST" class="mt-6 space-y-5">
                @csrf
                @if($product->has_variants)
                    <div>
                        <span class="label">Option</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach($product->variants as $v)
                                <button type="button" @click="variant='{{ $v->id }}'"
                                    class="rounded-md border px-4 py-2 text-sm"
                                    :class="variant==='{{ $v->id }}' ? 'border-gold-500 bg-gold-100 text-gold-800' : 'border-ink-100 hover:border-gold-300'"
                                    @disabled($v->stock_quantity <= 0)>
                                    {{ $v->attributes['Option'] ?? $v->label }}
                                </button>
                            @endforeach
                        </div>
                        <input type="hidden" name="variant_id" :value="variant">
                    </div>
                @endif

                <div class="flex items-center gap-4">
                    <div class="inline-flex items-center rounded-md border border-ink-100">
                        <button type="button" @click="qty = Math.max(1, qty-1)" class="px-3 py-2">−</button>
                        <input name="qty" x-model="qty" class="w-12 text-center border-0 focus:ring-0" readonly>
                        <button type="button" @click="qty++" class="px-3 py-2">+</button>
                    </div>

                    @if($product->isAvailable())
                        <button type="submit" class="btn-primary flex-1"
                            x-bind:disabled="{{ $product->has_variants ? 'true' : 'false' }} && !variant">
                            Add to cart
                        </button>
                    @else
                        <button type="button" disabled class="btn-dark flex-1 opacity-60">Sold out</button>
                    @endif
                </div>
            </form>

            <div class="mt-6 rounded-lg bg-gold-100/60 p-4 text-sm text-ink-700/80 space-y-1">
                <p>💵 Cash on delivery available</p>
                <p>🚚 Delivery: ৳{{ \App\Models\Setting::get('shipping_inside', config('store.shipping.inside_dhaka')) }} inside Dhaka, ৳{{ \App\Models\Setting::get('shipping_outside', config('store.shipping.outside_dhaka')) }} outside</p>
            </div>

            @if($product->description)
                <div class="mt-8 prose prose-sm max-w-none">
                    <h2 class="font-display text-xl font-semibold mb-2">Description</h2>
                    <div class="text-ink-700/85 whitespace-pre-line">{!! nl2br(e($product->description)) !!}</div>
                </div>
            @endif
        </div>
    </div>

    @if($related->isNotEmpty())
        <section class="mt-16">
            <h2 class="font-display text-2xl font-semibold mb-6">You may also like</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
                @foreach($related as $p)
                    <x-product-card :product="$p" />
                @endforeach
            </div>
        </section>
    @endif
</div>
@endsection
