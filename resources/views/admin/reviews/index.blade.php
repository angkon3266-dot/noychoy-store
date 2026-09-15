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
    $lastProduct = (int) (old('product_id') ?: session('last_product_id'));
@endphp

@php
    // Rows come back keyed by the id Alpine gave them, so a failed submit
    // re-opens the same batch with the same fields filled in.
    $oldRows = array_values((array) old('reviews', []));
@endphp

{{-- Writing down the reviews that arrived in Messenger or WhatsApp --}}
<div class="card p-5 mb-6"
     x-data="reviewBatch(@js($oldRows), @js($today))"
     x-init="if ({{ $errors->any() || session('last_product_id') ? 'true' : 'false' }}) open = true">
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
        <a href="{{ route('admin.reviews.index', ['status' => $key]) }}"
           class="px-3 py-1.5 rounded-full text-sm {{ $current === $key ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700 hover:bg-ink-200' }}">
            {{ $label }} <span class="opacity-60">({{ $counts[$key] }})</span>
        </a>
    @endforeach
    <a href="{{ route('admin.reviews.index', ['status' => 'all']) }}" class="px-3 py-1.5 rounded-full text-sm {{ $current === 'all' ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700 hover:bg-ink-200' }}">All</a>
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
        <div class="card p-10 text-center text-ink-700/50">No reviews here.</div>
    @endforelse
</div>

<div class="mt-6">{{ $reviews->links() }}</div>
@endsection
