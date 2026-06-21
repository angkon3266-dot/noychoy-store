{{-- Description, related & recently-viewed. Not Alpine-dependent. --}}
@if($product->description)
    <section class="mt-12 max-w-3xl">
        <h2 class="font-display text-2xl font-semibold mb-3">Description</h2>
        <div class="prose prose-sm max-w-none text-ink-700/85">{!! nl2br(e($product->description)) !!}</div>
    </section>
@endif

@if($product->custom_show && $product->custom_label && $product->custom_value)
    <section class="mt-6 max-w-3xl">
        <div class="inline-flex items-center gap-2 rounded-lg bg-gold-50 border border-gold-100 px-4 py-2 text-sm">
            <span class="text-ink-700/60">{{ $product->custom_label }}:</span>
            <span class="font-medium">{{ $product->custom_value }}</span>
        </div>
    </section>
@endif

{{-- ── Customer reviews ─────────────────────────────────────────────── --}}
@php
    $reviews = $product->approvedReviews;
    $avg = $product->average_rating;
    $count = $product->review_count;
    $dist = collect(range(5, 1))->mapWithKeys(fn ($s) => [$s => $reviews->where('rating', $s)->count()]);
@endphp
<section class="mt-16 border-t border-ink-100 pt-10" id="reviews">
    <h2 class="font-display text-2xl font-semibold mb-6">Customer reviews</h2>

    <div class="grid md:grid-cols-3 gap-8">
        {{-- Summary --}}
        <div>
            @if($count)
                <div class="flex items-end gap-2">
                    <span class="text-4xl font-semibold">{{ $avg }}</span>
                    <span class="text-ink-700/50 mb-1">/ 5</span>
                </div>
                <div class="flex text-gold-500 mt-1">
                    @for($i = 1; $i <= 5; $i++)
                        <svg class="w-5 h-5 {{ $i <= round($avg) ? '' : 'text-ink-200' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.96a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.37 2.448a1 1 0 00-.363 1.118l1.287 3.96c.3.922-.755 1.688-1.54 1.118l-3.37-2.448a1 1 0 00-1.175 0l-3.37 2.448c-.784.57-1.838-.196-1.539-1.118l1.287-3.96a1 1 0 00-.363-1.118L2.05 9.387c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.286-3.96z"/></svg>
                    @endfor
                </div>
                <p class="text-sm text-ink-700/60 mt-1">{{ $count }} review{{ $count > 1 ? 's' : '' }}</p>
                <div class="mt-4 space-y-1.5">
                    @foreach($dist as $star => $n)
                        <div class="flex items-center gap-2 text-xs">
                            <span class="w-6 text-ink-700/60">{{ $star }}★</span>
                            <div class="flex-1 h-2 rounded-full bg-ink-100 overflow-hidden"><div class="h-full bg-gold-500" style="width: {{ $count ? round($n / $count * 100) : 0 }}%"></div></div>
                            <span class="w-6 text-right text-ink-700/50">{{ $n }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-ink-700/60">No reviews yet. Be the first to review this piece!</p>
            @endif
        </div>

        {{-- List --}}
        <div class="md:col-span-2 space-y-6">
            @forelse($reviews->take(8) as $review)
                <div class="border-b border-ink-100 pb-5 last:border-0">
                    <div class="flex items-center gap-2">
                        <div class="flex text-gold-500">
                            @for($i = 1; $i <= 5; $i++)<svg class="w-4 h-4 {{ $i <= $review->rating ? '' : 'text-ink-200' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.96a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.37 2.448a1 1 0 00-.363 1.118l1.287 3.96c.3.922-.755 1.688-1.54 1.118l-3.37-2.448a1 1 0 00-1.175 0l-3.37 2.448c-.784.57-1.838-.196-1.539-1.118l1.287-3.96a1 1 0 00-.363-1.118L2.05 9.387c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.286-3.96z"/></svg>@endfor
                        </div>
                        <span class="font-medium text-sm">{{ $review->author_name }}</span>
                        @if($review->is_verified_buyer)<span class="badge bg-green-100 text-green-700 text-[10px]">✓ Verified buyer</span>@endif
                        <span class="text-xs text-ink-700/40 ml-auto">{{ $review->created_at->format('d M Y') }}</span>
                    </div>
                    @if($review->title)<p class="font-medium mt-2">{{ $review->title }}</p>@endif
                    @if($review->body)<p class="text-sm text-ink-700/80 mt-1">{{ $review->body }}</p>@endif
                    @if($review->photo_urls)
                        <div class="mt-3 flex gap-2 flex-wrap">
                            @foreach($review->photo_urls as $url)<img src="{{ $url }}" class="w-20 h-20 rounded-lg object-cover border border-ink-100" alt="Customer photo" loading="lazy">@endforeach
                        </div>
                    @endif
                </div>
            @empty
            @endforelse

            {{-- Write a review --}}
            <details class="rounded-xl border border-ink-100 p-5" x-data="{ rating: 0, hover: 0 }">
                <summary class="font-medium cursor-pointer">✍️ Write a review</summary>
                @if(session('success'))<p class="mt-3 text-sm text-green-700 bg-green-50 rounded p-2">{{ session('success') }}</p>@endif
                @if($errors->any())<div class="mt-3 text-sm text-red-700 bg-red-50 rounded p-2">{{ $errors->first() }}</div>@endif
                <form action="{{ route('review.store', $product) }}" method="POST" enctype="multipart/form-data" class="mt-4 space-y-3">
                    @csrf
                    <div>
                        <span class="label">Your rating *</span>
                        <div class="flex gap-1" @mouseleave="hover = 0">
                            @for($i = 1; $i <= 5; $i++)
                                <button type="button" @click="rating = {{ $i }}" @mouseenter="hover = {{ $i }}"
                                    class="text-2xl transition" :class="(hover || rating) >= {{ $i }} ? 'text-gold-500' : 'text-ink-200'">★</button>
                            @endfor
                        </div>
                        <input type="hidden" name="rating" :value="rating" required>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <input name="author_name" placeholder="Your name *" class="input" required value="{{ auth('customer')->user()->name ?? '' }}">
                        <input name="phone" placeholder="Phone (for verified badge)" class="input">
                    </div>
                    <input name="title" placeholder="Headline (optional)" class="input">
                    <textarea name="body" rows="3" placeholder="Share your experience…" class="input"></textarea>
                    <div>
                        <label class="label">Add photos (optional, up to 4)</label>
                        <input type="file" name="photos[]" accept="image/*" multiple class="input text-sm">
                    </div>
                    <button class="btn-primary" :disabled="!rating">Submit review</button>
                    <p class="text-xs text-ink-700/50">Reviews appear after approval.</p>
                </form>
            </details>
        </div>
    </div>
</section>

@if(theme('show_frequently_bought') && isset($crossSells) && $crossSells->isNotEmpty())
    @php
        $fbtItems = collect([$product])->merge($crossSells)->take(4);
    @endphp
    <section class="mt-16" x-data="{
        sel: { {{ $fbtItems->map(fn($p) => $p->id.': '.($p->isAvailable() && !$p->has_variants ? 'true' : 'false'))->implode(', ') }} },
        prices: { {{ $fbtItems->map(fn($p) => $p->id.': '.(float) $p->price)->implode(', ') }} },
        get total() { return Object.keys(this.sel).reduce((t,id) => t + (this.sel[id] ? this.prices[id] : 0), 0); },
        fmt(n){ return '৳' + Number(n).toLocaleString(); }
    }">
        <h2 class="font-display text-2xl font-semibold mb-6">Frequently bought together</h2>
        <div class="grid lg:grid-cols-3 gap-6 items-start">
            <div class="lg:col-span-2 flex flex-wrap items-center gap-3">
                @foreach($fbtItems as $i => $p)
                    <label class="relative block w-32">
                        <input type="checkbox" class="absolute top-2 left-2 z-10 h-4 w-4 accent-gold-600"
                               x-model="sel[{{ $p->id }}]" @disabled(!$p->isAvailable() || $p->has_variants)>
                        <span class="block aspect-square rounded-lg overflow-hidden bg-gold-100 border border-ink-100">
                            @if($p->thumbnail)<img src="{{ $p->thumbnail }}" class="h-full w-full object-cover" alt="{{ $p->name }}">@endif
                        </span>
                        <span class="mt-1 block text-xs truncate">{{ $p->name }}</span>
                        <span class="block text-xs font-semibold text-gold-700">{{ money($p->price) }}</span>
                        @if($p->has_variants)<span class="block text-[10px] text-ink-700/50">choose options on its page</span>@endif
                    </label>
                    @if(!$loop->last)<span class="text-2xl text-ink-300">+</span>@endif
                @endforeach
            </div>
            <div class="card p-5">
                <p class="text-sm text-ink-700/70">Total for selected</p>
                <p class="text-2xl font-semibold text-gold-700" x-text="fmt(total)"></p>
                <form action="{{ route('cart.add-many') }}" method="POST" class="mt-3">
                    @csrf
                    @foreach($fbtItems as $p)
                        @if($p->isAvailable() && !$p->has_variants)
                            <input type="checkbox" name="product_ids[]" value="{{ $p->id }}" x-model="sel[{{ $p->id }}]" class="hidden">
                        @endif
                    @endforeach
                    <button class="btn-primary w-full">Add selected to cart</button>
                </form>
            </div>
        </div>
    </section>
@endif

@if($related->isNotEmpty())
    <section class="mt-16">
        <h2 class="font-display text-2xl font-semibold mb-6">You may also like</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
            @foreach($related as $p)<x-product-card :product="$p" />@endforeach
        </div>
    </section>
@endif

@if(theme('show_recently_viewed'))
    @php
        $recentIds = collect(session('recently_viewed', []))->reject(fn ($id) => $id === $product->id)->take(4);
        $recentlyViewed = $recentIds->isNotEmpty()
            ? \App\Models\Product::published()->whereIn('id', $recentIds)->with('images')->get()
            : collect();
    @endphp
    @if($recentlyViewed->isNotEmpty())
        <section class="mt-16">
            <h2 class="font-display text-2xl font-semibold mb-6">Recently viewed</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
                @foreach($recentlyViewed as $p)<x-product-card :product="$p" />@endforeach
            </div>
        </section>
    @endif
@endif
