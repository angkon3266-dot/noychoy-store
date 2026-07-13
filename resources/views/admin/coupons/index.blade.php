@extends('layouts.admin')
@section('title', 'Coupons')
@section('heading', 'Coupons')

@section('content')
@php $c = $editing; @endphp
<div class="grid lg:grid-cols-3 gap-6">
    <div class="card p-6 h-fit lg:sticky lg:top-20"
         x-data="{ scope: '{{ old('applies_to', $c->applies_to ?? 'all') }}', type: '{{ old('type', $c->type ?? 'fixed') }}' }">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-semibold">{{ $editing ? 'Edit coupon' : 'New coupon' }}</h2>
            @if($editing)<a href="{{ route('admin.coupons.index') }}" class="text-xs text-ink-700/60 hover:underline">+ New instead</a>@endif
        </div>
        @if($errors->any())<div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mb-3"><ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

        <form action="{{ $editing ? route('admin.coupons.update', $editing) : route('admin.coupons.store') }}" method="POST" class="space-y-3">
            @csrf
            @if($editing)@method('PUT')@endif

            <div><label class="label">Code *</label><input name="code" value="{{ old('code', $c->code ?? '') }}" class="input uppercase" required></div>

            <div class="grid grid-cols-2 gap-3">
                <div><label class="label">Type</label>
                    <select name="type" x-model="type" class="input">
                        <option value="fixed">Fixed ৳</option>
                        <option value="percent">Percent %</option>
                    </select>
                </div>
                <div><label class="label">Value *</label><input name="value" type="number" step="0.01" value="{{ old('value', $c->value ?? '') }}" class="input" required></div>
            </div>

            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="free_shipping" value="1" @checked(old('free_shipping', $c->free_shipping ?? false))> Also grant free shipping</label>

            {{-- Scope --}}
            <div>
                <label class="label">Applies to</label>
                <select name="applies_to" x-model="scope" class="input">
                    <option value="all">Entire cart</option>
                    <option value="categories">Specific categories</option>
                    <option value="products">Specific products</option>
                </select>
            </div>
            <div x-show="scope === 'categories'" x-cloak>
                <label class="label">Categories</label>
                <select name="category_ids[]" multiple size="5" class="input">
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(in_array($cat->id, (array) old('category_ids', $c->category_ids ?? [])))>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div x-show="scope === 'products'" x-cloak>
                <label class="label">Products</label>
                <select name="product_ids[]" multiple size="5" class="input">
                    @foreach($products as $p)
                        <option value="{{ $p->id }}" @selected(in_array($p->id, (array) old('product_ids', $c->product_ids ?? [])))>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="exclude_sale_items" value="1" @checked(old('exclude_sale_items', $c->exclude_sale_items ?? false))> Exclude items already on sale</label>

            {{-- Conditions --}}
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label">Min order ৳</label><input name="min_order" type="number" step="0.01" value="{{ old('min_order', $c->min_order ?? '') }}" class="input"></div>
                <div><label class="label">Min qty</label><input name="min_qty" type="number" min="1" value="{{ old('min_qty', $c->min_qty ?? '') }}" class="input"></div>
                <div><label class="label">Max qty</label><input name="max_qty" type="number" min="1" value="{{ old('max_qty', $c->max_qty ?? '') }}" class="input"></div>
                <div><label class="label">Usage limit (total)</label><input name="usage_limit" type="number" min="1" value="{{ old('usage_limit', $c->usage_limit ?? '') }}" class="input"></div>
                <div><label class="label">Limit per customer</label><input name="per_customer_limit" type="number" min="1" value="{{ old('per_customer_limit', $c->per_customer_limit ?? '') }}" class="input"></div>
                <div><label class="label">Expires at</label><input name="expires_at" type="date" value="{{ old('expires_at', $c?->expires_at?->format('Y-m-d')) }}" class="input"></div>
            </div>

            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $c->is_active ?? true))> Active</label>
            <button class="btn-primary w-full">{{ $editing ? 'Save changes' : 'Create coupon' }}</button>
        </form>
    </div>

    <div class="lg:col-span-2 card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Code</th><th class="px-4 py-3">Discount</th><th class="px-4 py-3">Scope</th><th class="px-4 py-3">Used</th><th class="px-4 py-3">Active</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($coupons as $cp)
                    <tr class="{{ $editing && $editing->id === $cp->id ? 'bg-gold-50' : '' }}">
                        <td class="px-4 py-3 font-medium">{{ $cp->code }}@if($cp->free_shipping)<span class="ml-1 badge bg-blue-100 text-blue-700 text-[10px]">+ship</span>@endif</td>
                        <td class="px-4 py-3">{{ $cp->type=='percent' ? rtrim(rtrim(number_format($cp->value,2),'0'),'.').'%' : money($cp->value) }}@if($cp->min_order)<span class="text-xs text-ink-700/50"> (min {{ money($cp->min_order) }})</span>@endif</td>
                        <td class="px-4 py-3 text-xs text-ink-700/70">
                            @switch($cp->applies_to)
                                @case('categories') {{ count($cp->category_ids ?? []) }} categor{{ count($cp->category_ids ?? [])==1?'y':'ies' }} @break
                                @case('products') {{ count($cp->product_ids ?? []) }} product(s) @break
                                @default Entire cart
                            @endswitch
                        </td>
                        <td class="px-4 py-3">{{ $cp->used_count }}{{ $cp->usage_limit ? '/'.$cp->usage_limit : '' }}</td>
                        <td class="px-4 py-3">{!! $cp->is_active ? '<span class="badge bg-green-100 text-green-700">Yes</span>' : '<span class="badge bg-ink-100 text-ink-700">No</span>' !!}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.coupons.index', ['edit' => $cp->id]) }}" class="text-gold-700 hover:underline mr-3">Edit</a>
                            <form action="{{ route('admin.coupons.destroy', $cp) }}" method="POST" class="inline" onsubmit="return confirm('Delete coupon?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Delete</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-ink-700/50">No coupons yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-6">{{ $coupons->links() }}</div>
@endsection
