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

<div class="grid lg:grid-cols-3 gap-6">
    {{-- Form --}}
    <div class="card p-6 h-fit" x-data="{ type: '{{ old('type', $editing->type ?? 'order_percent') }}' }">
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
    <div class="lg:col-span-2 card overflow-hidden">
        <table class="w-full text-sm">
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
