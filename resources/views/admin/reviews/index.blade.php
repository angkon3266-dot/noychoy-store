@extends('layouts.admin')
@section('title', 'Reviews')
@section('heading', 'Reviews')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif
@if($errors->any())<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm"><ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

@php
    // Reviews are written in Dhaka time; the column is UTC. Every date on this
    // screen goes through store_time() so the day shown is the day meant.
    $today = store_time(now())->format('Y-m-d');
    $lastProduct = (int) (old('product_id') ?: session('last_product_id') ?: $product?->id);
    // Every link on this screen keeps the product it is looking at.
    $scoped = fn (array $extra = []) => route('admin.reviews.index', array_filter(
        array_merge(['product' => $product?->id], $extra),
        fn ($v) => $v !== null && $v !== '',
    ));
@endphp

{{-- Find a product, open its reviews --}}
<div class="card p-5 mb-4">
    <form action="{{ route('admin.reviews.index') }}" method="GET" class="relative"
          x-data="reviewProductPicker(@js($picker), @js(route('admin.reviews.index')), @js($term))"
          @click.outside="open = false">
        <label for="review-product-search" class="label">Find a product to see its reviews</label>
        <div class="flex gap-2">
            <input id="review-product-search" type="search" name="q" x-model="q" autocomplete="off"
                   @focus="open = true" @input="open = true; active = -1"
                   @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
                   @keydown.enter="choose($event)" @keydown.escape.prevent="open = false"
                   class="input flex-1" placeholder="Product name, SKU or #ID — e.g. kundan, #12"
                   role="combobox" aria-autocomplete="list" aria-controls="review-product-results"
                   :aria-expanded="open && results.length > 0">
            <button class="btn-outline">Search</button>
            @if($product || $term !== '')
                <a href="{{ route('admin.reviews.index') }}" class="btn-outline" title="Back to every review">Clear</a>
            @endif
        </div>

        <ul id="review-product-results" role="listbox" x-show="open && results.length" x-cloak
            class="absolute z-20 left-0 right-0 mt-1 max-h-80 overflow-y-auto rounded-lg border border-ink-100 bg-white shadow-lg">
            <template x-for="(p, i) in results" :key="p.id">
                <li role="option" :aria-selected="i === active">
                    <a :href="urlFor(p)" @mouseenter="active = i"
                       class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                       :class="i === active ? 'bg-ink-50' : ''">
                        <span class="min-w-0 truncate">
                            <span x-text="p.name"></span>
                            <span class="text-xs text-ink-700/40" x-show="p.serial" x-text="'#' + p.serial"></span>
                        </span>
                        <span class="shrink-0 text-xs text-ink-700/60">
                            <span x-text="p.total + (p.total === 1 ? ' review' : ' reviews')"></span>
                            <span x-show="p.pending" class="ml-1 badge bg-amber-100 text-amber-700 text-[10px]" x-text="p.pending + ' pending'"></span>
                        </span>
                    </a>
                </li>
            </template>
        </ul>
    </form>
</div>

@if($matches !== null)
    {{-- The search matched several products (or none) — pick one --}}
    <div class="card p-5 mb-6">
        @if($matches->isEmpty())
            <p class="text-sm text-ink-700/70">No product matches “{{ $term }}”. Try fewer words, or the Product ID.</p>
        @else
            <p class="text-sm text-ink-700/70 mb-3">
                {{ $matches->count() }} products match “{{ $term }}”{{ $matches->count() >= 30 ? ' (showing the first 30)' : '' }} — pick one to see its reviews.
            </p>
            <div class="divide-y divide-ink-100">
                @foreach($matches as $m)
                    <a href="{{ route('admin.reviews.index', ['product' => $m->id]) }}"
                       class="flex items-center gap-3 py-2.5 hover:bg-ink-50 -mx-2 px-2 rounded">
                        @if($m->primaryImage)
                            <img src="{{ image_variant($m->primaryImage->url) ?: $m->primaryImage->url }}" alt="" class="w-10 h-10 rounded object-cover border border-ink-100 shrink-0">
                        @else
                            <span class="w-10 h-10 rounded bg-ink-100 shrink-0"></span>
                        @endif
                        <span class="flex-1 min-w-0">
                            <span class="block text-sm font-medium break-words">{{ $m->name }}@if($m->trashed()) <span class="text-xs text-red-600">(deleted)</span>@endif</span>
                            <span class="block text-xs text-ink-700/50">#{{ $m->serial }}{{ $m->sku ? ' · '.$m->sku : '' }}</span>
                        </span>
                        <span class="text-xs text-ink-700/60 shrink-0 text-right">
                            {{ $m->reviews_count }} {{ $m->reviews_count === 1 ? 'review' : 'reviews' }}
                            @if($m->pending_count)<span class="ml-1 badge bg-amber-100 text-amber-700 text-[10px]">{{ $m->pending_count }} pending</span>@endif
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endif

@if($product)
    {{-- The product being looked at, and what shoppers see of its rating --}}
    <div class="card p-5 mb-4" x-data>
        <div class="flex flex-wrap items-start gap-4">
            @if($product->primaryImage)
                <img src="{{ image_variant($product->primaryImage->url) ?: $product->primaryImage->url }}" alt="" class="w-16 h-16 rounded-lg object-cover border border-ink-100 shrink-0">
            @endif
            <div class="flex-1 min-w-[200px]">
                <p class="font-semibold">{{ $product->name }}</p>
                <p class="text-xs text-ink-700/50 mt-0.5">
                    Product ID #{{ $product->serial }}{{ $product->sku ? ' · SKU '.$product->sku : '' }}
                    @if($product->trashed()) · <span class="text-red-600">deleted product</span>@endif
                </p>
                <p class="text-sm mt-2">
                    @if($summary['avg'])
                        <span class="text-gold-500">{{ str_repeat('★', (int) round($summary['avg'])) }}<span class="text-ink-200">{{ str_repeat('★', 5 - (int) round($summary['avg'])) }}</span></span>
                        <span class="font-medium">{{ number_format($summary['avg'], 1) }}</span>
                        <span class="text-ink-700/60">from {{ $summary['total'] }} approved {{ $summary['total'] === 1 ? 'review' : 'reviews' }} — what shoppers see</span>
                    @else
                        <span class="text-ink-700/60">No approved reviews yet — shoppers see no rating on this piece.</span>
                    @endif
                </p>
            </div>
            @if($summary['total'])
                <div class="w-full sm:w-56 space-y-1">
                    @foreach($summary['dist'] as $stars => $n)
                        <div class="flex items-center gap-2 text-xs text-ink-700/60">
                            <span class="w-6 shrink-0">{{ $stars }}★</span>
                            <span class="flex-1 h-1.5 rounded-full bg-ink-100 overflow-hidden">
                                <span class="block h-full bg-gold-500" style="width: {{ $summary['total'] ? round($n / $summary['total'] * 100) : 0 }}%"></span>
                            </span>
                            <span class="w-6 shrink-0 text-right">{{ $n }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="flex flex-wrap gap-2 mt-4">
            @unless($product->trashed())
                <button type="button" class="btn-primary text-sm py-1.5" @click="$dispatch('open-review-batch')">+ Add reviews to this product</button>
                <a href="{{ route('admin.products.edit', $product) }}" class="btn-outline text-sm py-1.5">Edit product</a>
                <a href="{{ route('product.show', $product) }}" target="_blank" rel="noopener" class="btn-outline text-sm py-1.5">View on site ↗</a>
            @endunless
        </div>
    </div>
@endif

@if($matches === null)
@php
    // Rows come back keyed by the id Alpine gave them, so a failed submit
    // re-opens the same batch with the same fields filled in.
    $oldRows = array_values((array) old('reviews', []));
@endphp

{{-- Writing down the reviews that arrived in Messenger or WhatsApp --}}
<div class="card p-5 mb-6"
     x-data="reviewBatch(@js($oldRows), @js($today))"
     x-init="if ({{ $errors->any() || session('last_product_id') ? 'true' : 'false' }}) open = true"
     @open-review-batch.window="open = true; $nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'start' }))">
    <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-3 text-left">
        <span>
            <span class="font-semibold">Add reviews yourself</span>
            <span class="block text-xs text-ink-700/60 mt-0.5">
                Pick one product, then type in every review it was given over Messenger, WhatsApp or the phone —
                each with the date it was actually written.
            </span>
        </span>
        <span class="text-ink-700/50 text-sm shrink-0" x-text="open ? '− Close' : '+ Add reviews'"></span>
    </button>

    <p class="text-xs text-ink-700/50 mt-2">
        Have the whole backlog in a spreadsheet already?
        <a href="{{ route('admin.reviews.import') }}" class="text-gold-700 underline">Import a CSV</a> —
        many products at once, each row with its own date.
    </p>

    <form action="{{ route('admin.reviews.store') }}" method="POST" enctype="multipart/form-data" class="mt-4" x-show="open" x-cloak>
        @csrf
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div class="sm:col-span-2">
                <label class="label">Product — every review below goes on this piece</label>
                <select name="product_id" class="input" required>
                    <option value="">— choose a product —</option>
                    @foreach($products as $p)
                        <option value="{{ $p->id }}" @selected($lastProduct === $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Show as</label>
                <select name="status" class="input">
                    @foreach($statuses as $key => $label)
                        <option value="{{ $key }}" @selected(old('status', 'approved') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-4 space-y-3">
            <template x-for="(row, i) in rows" :key="row.id">
                <div class="rounded-lg border border-ink-100 p-4">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <span class="text-xs font-medium text-ink-700/60">Review <span x-text="i + 1"></span></span>
                        <button type="button" x-show="rows.length > 1" @click="rows.splice(i, 1)"
                                class="text-xs text-red-600 hover:underline">Remove</button>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="label">Customer name</label>
                            <input type="text" :name="`reviews[${row.id}][author_name]`" x-model="row.author_name"
                                   class="input" maxlength="120" required>
                        </div>
                        <div>
                            <label class="label">Date shown</label>
                            <input type="date" :name="`reviews[${row.id}][reviewed_on]`" x-model="row.reviewed_on"
                                   max="{{ $today }}" class="input">
                        </div>
                        <div>
                            <label class="label">Rating</label>
                            <select :name="`reviews[${row.id}][rating]`" x-model="row.rating" class="input" required>
                                @foreach([5, 4, 3, 2, 1] as $r)
                                    <option value="{{ $r }}">{{ str_repeat('★', $r) }}{{ str_repeat('☆', 5 - $r) }} ({{ $r }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="label">Phone <span class="text-ink-700/40">(optional)</span></label>
                            <input type="text" :name="`reviews[${row.id}][phone]`" x-model="row.phone"
                                   class="input" maxlength="20" placeholder="01XXXXXXXXX">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="label">Headline <span class="text-ink-700/40">(optional)</span></label>
                            <input type="text" :name="`reviews[${row.id}][title]`" x-model="row.title"
                                   class="input" maxlength="150" placeholder="Onek shundor!">
                        </div>
                        <div class="flex items-end">
                            <label class="flex items-center gap-2 rounded-lg border border-ink-100 px-3 py-2.5 text-sm w-full">
                                <input type="hidden" :name="`reviews[${row.id}][is_verified_buyer]`" value="0">
                                <input type="checkbox" :name="`reviews[${row.id}][is_verified_buyer]`" value="1" x-model="row.is_verified_buyer">
                                Verified buyer
                            </label>
                        </div>
                        <div class="flex items-end">
                            <div class="w-full">
                                <label class="label">Photos <span class="text-ink-700/40">(up to 4)</span></label>
                                <input type="file" :name="`reviews[${row.id}][photos][]`" accept="image/*" multiple class="input py-2">
                            </div>
                        </div>
                        <div class="sm:col-span-2 lg:col-span-4">
                            <label class="label">What the customer wrote</label>
                            <textarea :name="`reviews[${row.id}][body]`" x-model="row.body" rows="2" class="input" maxlength="2000"></textarea>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <div class="flex flex-wrap items-center gap-3 mt-4">
            <button type="button" @click="addRow()" x-show="rows.length < 25" class="btn-outline text-xs py-1">+ Another review</button>
            <button class="btn-primary">Save <span x-text="rows.length"></span> <span x-text="rows.length === 1 ? 'review' : 'reviews'"></span></button>
            <p class="text-xs text-ink-700/50 flex-1 min-w-[220px]">
                Ticking <strong>Verified buyer</strong> puts the ✓ badge on it. Leave the phone in and we check the
                orders ourselves — a phone that bought this piece is marked verified either way. An empty row is
                simply skipped.
            </p>
        </div>
    </form>
</div>

<div class="flex flex-wrap gap-2 mb-4">
    @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'hidden' => 'Hidden'] as $key => $label)
        <a href="{{ $scoped(['status' => $key]) }}"
           class="px-3 py-1.5 rounded-full text-sm {{ $current === $key ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700 hover:bg-ink-200' }}">
            {{ $label }} <span class="opacity-60">({{ $counts[$key] }})</span>
        </a>
    @endforeach
    <a href="{{ $scoped(['status' => 'all']) }}" class="px-3 py-1.5 rounded-full text-sm {{ $current === 'all' ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700 hover:bg-ink-200' }}">All</a>
</div>

<div class="space-y-3">
    @forelse($reviews as $review)
        <div class="card p-5" x-data="{ edit: false }">
            <div class="flex flex-wrap items-start justify-between gap-3" x-show="! edit">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-gold-500">{{ str_repeat('★', $review->rating) }}<span class="text-ink-200">{{ str_repeat('★', 5 - $review->rating) }}</span></span>
                        <span class="font-medium text-sm">{{ $review->author_name }}</span>
                        @if($review->is_verified_buyer)<span class="badge bg-green-100 text-green-700 text-[10px]">✓ Verified</span>@endif
                        <span class="badge {{ ['pending'=>'bg-amber-100 text-amber-700','approved'=>'bg-green-100 text-green-700','hidden'=>'bg-ink-100 text-ink-600'][$review->status] }} text-[10px] capitalize">{{ $review->status }}</span>
                    </div>
                    <p class="text-xs text-ink-700/50 mt-0.5">
                        on @if($review->product)<a href="{{ route('admin.products.edit', $review->product) }}" class="text-gold-700 hover:underline">{{ $review->product->name }}</a>@else<span class="italic">deleted product</span>@endif
                        · {{ store_time($review->created_at)->format('d M Y, g:i a') }}{{ $review->phone ? ' · '.$review->phone : '' }}
                    </p>
                    @if($review->title)<p class="font-medium mt-2">{{ $review->title }}</p>@endif
                    @if($review->body)<p class="text-sm text-ink-700/80 mt-1">{{ $review->body }}</p>@endif
                    @if($review->photo_urls)
                        <div class="mt-2 flex gap-2 flex-wrap">
                            @foreach($review->photo_urls as $url)<img src="{{ $url }}" class="w-16 h-16 rounded object-cover border border-ink-100" alt="">@endforeach
                        </div>
                    @endif
                </div>
                <div class="flex flex-col gap-1.5 shrink-0">
                    @if($review->status !== 'approved')
                        <form action="{{ route('admin.reviews.status', $review) }}" method="POST">@csrf @method('PATCH')<input type="hidden" name="status" value="approved"><button class="text-xs text-green-700 hover:underline">✓ Approve</button></form>
                    @endif
                    @if($review->status !== 'hidden')
                        <form action="{{ route('admin.reviews.status', $review) }}" method="POST">@csrf @method('PATCH')<input type="hidden" name="status" value="hidden"><button class="text-xs text-ink-600 hover:underline">⊘ Turn off</button></form>
                    @endif
                    <button type="button" @click="edit = true" class="text-xs text-ink-600 hover:underline text-left">✎ Edit</button>
                    <form action="{{ route('admin.reviews.destroy', $review) }}" method="POST" onsubmit="return confirm('Delete this review permanently?')">@csrf @method('DELETE')<button class="text-xs text-red-600 hover:underline">Delete</button></form>
                </div>
            </div>

            {{-- The same review, open for correction: wording, rating, and the date it shows --}}
            <form action="{{ route('admin.reviews.update', $review) }}" method="POST" x-show="edit" x-cloak>
                @csrf @method('PATCH')
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label class="label">Product</label>
                        <select name="product_id" class="input" required>
                            {{-- A deleted product is not in the list; without its own option the
                                 browser would submit the first product and move the review there. --}}
                            @unless($products->contains('id', (int) $review->product_id))
                                <option value="{{ $review->product_id }}" selected>{{ $product && $product->id === (int) $review->product_id ? $product->name : 'Deleted product' }} (deleted — keeps it where it is)</option>
                            @endunless
                            @foreach($products as $p)
                                <option value="{{ $p->id }}" @selected((int) $review->product_id === $p->id)>{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">Customer name</label>
                        <input type="text" name="author_name" value="{{ $review->author_name }}" class="input" maxlength="120" required>
                    </div>
                    <div>
                        <label class="label">Date shown</label>
                        <input type="date" name="reviewed_on" value="{{ store_time($review->created_at)->format('Y-m-d') }}" max="{{ $today }}" class="input">
                    </div>
                    <div>
                        <label class="label">Rating</label>
                        <select name="rating" class="input" required>
                            @foreach([5, 4, 3, 2, 1] as $r)
                                <option value="{{ $r }}" @selected((int) $review->rating === $r)>{{ str_repeat('★', $r) }}{{ str_repeat('☆', 5 - $r) }} ({{ $r }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">Phone</label>
                        <input type="text" name="phone" value="{{ $review->phone }}" class="input" maxlength="20">
                    </div>
                    <div>
                        <label class="label">Show as</label>
                        <select name="status" class="input">
                            @foreach($statuses as $key => $label)
                                <option value="{{ $key }}" @selected($review->status === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end">
                        <label class="flex items-center gap-2 rounded-lg border border-ink-100 px-3 py-2.5 text-sm w-full">
                            <input type="checkbox" name="is_verified_buyer" value="1" @checked($review->is_verified_buyer)>
                            Verified buyer
                        </label>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-4">
                        <label class="label">Headline</label>
                        <input type="text" name="title" value="{{ $review->title }}" class="input" maxlength="150">
                    </div>
                    <div class="sm:col-span-2 lg:col-span-4">
                        <label class="label">What the customer wrote</label>
                        <textarea name="body" rows="3" class="input" maxlength="2000">{{ $review->body }}</textarea>
                    </div>
                </div>
                <div class="flex items-center gap-3 mt-4">
                    <button class="btn-primary">Save changes</button>
                    <button type="button" @click="edit = false" class="btn-outline">Cancel</button>
                </div>
            </form>
        </div>
    @empty
        <div class="card p-10 text-center text-ink-700/50">
            @if($product)
                No {{ $current !== 'all' ? strtolower($statuses[$current] ?? $current).' ' : '' }}reviews for {{ $product->name }} yet.
            @else
                No reviews here.
            @endif
        </div>
    @endforelse
</div>

<div class="mt-6">{{ $reviews->links() }}</div>
@endif
@endsection
