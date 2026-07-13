@extends('layouts.admin')
@section('title', 'Offers')
@section('heading', 'Offers & promotions')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif

<p class="text-sm text-ink-700/70 mb-4 max-w-3xl">
    Automatic promotions that apply at checkout <strong>and</strong> show on product pages to boost conversions —
    e.g. “Free delivery over ৳2000”, “Buy any 2, get 5% off”, “Members get an extra 3% off”.
    For single-use codes use <a href="{{ route('admin.coupons.index') }}" class="text-gold-700 underline">Coupons</a>.
</p>

{{-- Registration offer (shown to guests, applied automatically to logged-in members) --}}
<div class="card p-5 mb-6 max-w-3xl">
    <h2 class="font-semibold mb-1">Register-for-discount offer</h2>
    <p class="text-xs text-ink-700/60 mb-3">Guests see this as a nudge at checkout &amp; on the register page. Logged-in customers get the discount automatically on every order. Set the percent to 0 to turn it off.</p>
    <form action="{{ route('admin.offers.register') }}" method="POST" class="flex flex-wrap items-end gap-3"
          x-data="{ rows: @js($memberOverrides), categories: @js($categories->map(fn($c)=>['id'=>$c->id,'name'=>$c->name])->values()), products: @js($products->map(fn($p)=>['id'=>$p->id,'name'=>$p->name])->values()) }">
        @csrf
        <div>
            <label class="label">Member discount %</label>
            <input name="register_offer_percent" type="number" step="0.1" min="0" max="90" value="{{ $registerOffer['percent'] }}" class="input w-32">
        </div>
        <div class="flex-1 min-w-[220px]">
            <label class="label">Nudge text</label>
            <input name="register_offer_text" value="{{ $registerOffer['text'] }}" class="input" placeholder="Create an account for an extra discount + points">
        </div>
        <div>
            <label class="label">Max uses</label>
            <input name="register_offer_max_uses" type="number" min="0" value="{{ $registerOffer['max_uses'] }}" class="input w-28" title="0 = unlimited">
        </div>
        <div>
            <label class="label">Per (days)</label>
            <input name="register_offer_window_days" type="number" min="1" value="{{ $registerOffer['window_days'] }}" class="input w-24">
        </div>

        {{-- Per-category / per-product overrides --}}
        <div class="w-full border-t border-ink-100 pt-3 mt-1">
            <h3 class="text-sm font-semibold text-ink-700 mb-1">Category / product overrides (optional)</h3>
            <p class="text-xs text-ink-700/50 mb-2">Members get the % above on everything. Add exceptions here — a different % for a category or a specific product.</p>
            <template x-for="(r, i) in rows" :key="i">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <select x-model="r.type" class="input py-1.5 text-sm w-32"><option value="category">Category</option><option value="product">Product</option></select>
                    <select x-model.number="r.id" class="input py-1.5 text-sm flex-1 min-w-[160px]">
                        <option value="">Choose…</option>
                        <template x-for="opt in (r.type==='product' ? products : categories)" :key="opt.id"><option :value="opt.id" x-text="opt.name"></option></template>
                    </select>
                    <input x-model.number="r.percent" type="number" step="0.1" min="0" max="90" class="input py-1.5 text-sm w-24" placeholder="% off">
                    <button type="button" @click="rows.splice(i,1)" class="text-red-500 px-1 text-lg leading-none">&times;</button>
                </div>
            </template>
            <button type="button" @click="rows.push({ type: 'category', id: '', percent: '' })" class="btn-outline text-xs py-1">+ Add override</button>
            <input type="hidden" name="member_overrides_json" :value="JSON.stringify(rows.filter(r => r.id && r.percent !== ''))">
        </div>

        <button class="btn-primary">Save</button>
        <p class="w-full text-xs text-ink-700/50 mt-1">Logged-in members see this discounted price on every product. The discount applies to at most <strong>Max uses</strong> orders within each rolling window of <strong>Per (days)</strong> days per customer (0 = unlimited).</p>
    </form>
</div>

{{-- Loyalty & points configuration --}}
<div class="card p-5 mb-6 max-w-3xl">
    <h2 class="font-semibold mb-1">Loyalty &amp; points</h2>
    <p class="text-xs text-ink-700/60 mb-3">Control how customers earn and spend points. Points are credited <strong>after an order is delivered</strong>.</p>
    <form action="{{ route('admin.offers.loyalty') }}" method="POST">
        @csrf
        <label class="flex items-center gap-2 text-sm mb-3"><input type="checkbox" name="enabled" value="1" @checked($loyalty['enabled'])> Enable the points / rewards program</label>
        <div class="grid sm:grid-cols-3 gap-3">
            <div>
                <label class="label">Points per ৳1000 spent</label>
                <input name="per_1000" type="number" step="1" min="0" value="{{ $loyalty['per_1000'] }}" class="input">
            </div>
            <div>
                <label class="label">৳ value of 100 points</label>
                <input name="value_per_100" type="number" step="0.01" min="0" value="{{ $loyalty['value_per_100'] }}" class="input">
            </div>
            <div>
                <label class="label">Points for a review</label>
                <input name="review" type="number" step="1" min="0" value="{{ $loyalty['review'] }}" class="input">
            </div>
            <div>
                <label class="label">Points for a social share</label>
                <input name="share" type="number" step="1" min="0" value="{{ $loyalty['share'] }}" class="input">
            </div>
            <div>
                <label class="label">Welcome bonus (signup)</label>
                <input name="signup" type="number" step="1" min="0" value="{{ $loyalty['signup'] }}" class="input">
            </div>
            <div>
                <label class="label">Referral (each side)</label>
                <input name="referral" type="number" step="1" min="0" value="{{ $loyalty['referral'] }}" class="input">
            </div>
            <div>
                <label class="label">Review-with-photo bonus</label>
                <input name="photo_bonus" type="number" step="1" min="0" value="{{ $loyalty['photo_bonus'] }}" class="input">
            </div>
        </div>
        <button class="btn-primary mt-3">Save loyalty settings</button>
    </form>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    {{-- Form --}}
    <div class="card p-6 h-fit" x-data="{ type: '{{ old('type', $editing->type ?? 'order_percent') }}', applies: '{{ old('applies_to', $editing->applies_to ?? 'all') }}', catQ: '' }">
        <h2 class="font-semibold mb-4">{{ $editing ? 'Edit offer' : 'New offer' }}</h2>
        @if($errors->any())<div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mb-3">{{ $errors->first() }}</div>@endif
        <form action="{{ $editing ? route('admin.offers.update', $editing) : route('admin.offers.store') }}" method="POST" class="space-y-3">
            @csrf
            @if($editing) @method('PUT') @endif
            <div><label class="label">Title (shown to customer) *</label><input name="title" value="{{ old('title', $editing->title ?? '') }}" class="input" placeholder="Free delivery over ৳2000" required></div>
            <div><label class="label">Short description</label><input name="description" value="{{ old('description', $editing->description ?? '') }}" class="input" placeholder="On all orders, nationwide"></div>
            <div>
                <label class="label">Type *</label>
                <select name="type" x-model="type" class="input">
                    @foreach($types as $key => $label)<option value="{{ $key }}" @selected(old('type', $editing->type ?? '')==$key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div x-show="type==='order_percent'">
                <label class="label">Discount %</label>
                <input name="percent" type="number" step="0.01" min="0.1" max="90" value="{{ old('percent', $editing->percent ?? '') }}" class="input" placeholder="5">
            </div>

            {{-- Scope --}}
            @php $selOffCats = collect(old('category_ids', $editing->category_ids ?? []))->map(fn($i)=>(int)$i)->all(); @endphp
            @php $selOffProds = collect(old('product_ids', $editing->product_ids ?? []))->map(fn($i)=>(int)$i)->all(); @endphp
            <div>
                <label class="label">Applies to *</label>
                <select name="applies_to" x-model="applies" class="input">
                    @foreach($scopes as $key => $label)<option value="{{ $key }}" @selected(old('applies_to', $editing->applies_to ?? 'all')==$key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div x-show="applies==='categories'" x-cloak>
                <input x-model="catQ" placeholder="Filter categories…" class="input py-1.5 mb-2">
                <div class="max-h-40 overflow-y-auto rounded-lg border border-ink-100 p-2 space-y-1">
                    @foreach($categories as $cat)
                        <label class="flex items-center gap-2 text-sm" x-show="catQ==='' || '{{ Str::lower($cat->name) }}'.includes(catQ.toLowerCase())">
                            <input type="checkbox" name="category_ids[]" value="{{ $cat->id }}" @checked(in_array($cat->id, $selOffCats))> {{ $cat->name }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div x-show="applies==='products'" x-cloak>
                <select name="product_ids[]" multiple size="6" class="input">
                    @foreach($products as $p)<option value="{{ $p->id }}" @selected(in_array($p->id, $selOffProds))>{{ $p->name }}</option>@endforeach
                </select>
                <p class="text-xs text-ink-700/50 mt-1">Ctrl/Cmd-click to select several.</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label">Min. cart value ৳</label><input name="min_subtotal" type="number" step="0.01" value="{{ old('min_subtotal', $editing->min_subtotal ?? '') }}" class="input" placeholder="any"></div>
                <div><label class="label">Min. items</label><input name="min_qty" type="number" min="1" value="{{ old('min_qty', $editing->min_qty ?? '') }}" class="input" placeholder="any"></div>
            </div>
            <div><label class="label">Badge label (optional)</label><input name="badge_label" value="{{ old('badge_label', $editing->badge_label ?? '') }}" class="input" placeholder="FREE SHIPPING"></div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="members_only" value="1" @checked(old('members_only', $editing->members_only ?? false))> Members only (logged-in customers) — for “register to save”</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="show_on_pdp" value="1" @checked(old('show_on_pdp', $editing->show_on_pdp ?? true))> Show on product pages</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $editing->is_active ?? true))> Active</label>
            <div><label class="label">Sort order</label><input name="sort" type="number" value="{{ old('sort', $editing->sort ?? 0) }}" class="input w-24"></div>
            <div class="flex gap-2">
                <button class="btn-primary flex-1">{{ $editing ? 'Save offer' : 'Create offer' }}</button>
                @if($editing)<a href="{{ route('admin.offers.index') }}" class="btn-outline">Cancel</a>@endif
            </div>
        </form>
    </div>

    {{-- List --}}
    <div class="lg:col-span-2 card overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Offer</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Conditions</th><th class="px-4 py-3">On PDP</th><th class="px-4 py-3">Active</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($offers as $offer)
                    <tr class="hover:bg-ink-50">
                        <td class="px-4 py-3"><div class="font-medium">{{ $offer->title }}</div><div class="text-xs text-ink-700/50">{{ $offer->description }}</div></td>
                        <td class="px-4 py-3 text-ink-700/70">{{ $types[$offer->type] }}{{ $offer->type==='order_percent' ? ' ('.rtrim(rtrim(number_format($offer->percent,2),'0'),'.').'%)' : '' }}{{ $offer->members_only ? ' · members' : '' }}</td>
                        <td class="px-4 py-3 text-xs text-ink-700/60">
                            {{ $offer->min_subtotal ? '≥ ৳'.number_format($offer->min_subtotal,0) : '' }}
                            {{ $offer->min_qty ? ($offer->min_subtotal ? ' & ' : '').'≥ '.$offer->min_qty.' items' : '' }}
                            {{ ! $offer->min_subtotal && ! $offer->min_qty ? 'always' : '' }}
                        </td>
                        <td class="px-4 py-3">{!! $offer->show_on_pdp ? '✓' : '—' !!}</td>
                        <td class="px-4 py-3">{!! $offer->is_active ? '<span class="badge bg-green-100 text-green-700">Yes</span>' : '<span class="badge bg-ink-100 text-ink-700">No</span>' !!}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.offers.index', ['edit' => $offer->id]) }}" class="text-gold-700 hover:underline">Edit</a>
                            <form action="{{ route('admin.offers.destroy', $offer) }}" method="POST" class="inline" onsubmit="return confirm('Delete this offer?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline ml-2">Delete</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-ink-700/50">No offers yet. Create one on the left.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
