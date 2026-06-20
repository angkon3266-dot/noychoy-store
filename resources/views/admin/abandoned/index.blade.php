@extends('layouts.admin')
@section('title', 'Abandoned carts')
@section('heading', 'Abandoned carts')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif

<div class="flex flex-wrap items-center gap-2 mb-4">
    <p class="text-sm text-ink-700/70">Phone numbers captured at checkout before an order was completed — call/SMS to recover the sale.</p>
    <div class="ml-auto flex gap-2">
        <a href="{{ route('admin.abandoned.index') }}" class="px-3 py-1.5 rounded-full text-sm {{ !request('filter') ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700' }}">All</a>
        <a href="{{ route('admin.abandoned.index', ['filter'=>'open']) }}" class="px-3 py-1.5 rounded-full text-sm {{ request('filter')==='open' ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700' }}">Not recovered</a>
        <a href="{{ route('admin.abandoned.index', ['filter'=>'recovered']) }}" class="px-3 py-1.5 rounded-full text-sm {{ request('filter')==='recovered' ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700' }}">Recovered</a>
    </div>
</div>

<div class="card overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr><th class="px-4 py-3">Contact</th><th class="px-4 py-3">Cart</th><th class="px-4 py-3">Value</th><th class="px-4 py-3">When</th><th class="px-4 py-3">Status</th><th></th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($carts as $cart)
                <tr class="{{ $cart->recovered ? 'opacity-50' : '' }}">
                    <td class="px-4 py-3">
                        <a href="tel:{{ $cart->phone }}" class="font-medium text-gold-700">{{ $cart->phone }}</a>
                        @if($cart->name)<div class="text-xs text-ink-700/60">{{ $cart->name }}</div>@endif
                    </td>
                    <td class="px-4 py-3 text-ink-700/70 text-xs max-w-xs">
                        {{ collect($cart->items)->map(fn($i) => $i['name'].' ×'.$i['qty'])->implode(', ') ?: $cart->item_count.' item(s)' }}
                    </td>
                    <td class="px-4 py-3">{{ money($cart->subtotal) }}</td>
                    <td class="px-4 py-3 text-ink-700/60 text-xs">{{ $cart->updated_at->diffForHumans() }}</td>
                    <td class="px-4 py-3">
                        @if($cart->recovered)<span class="badge bg-green-100 text-green-700">Recovered</span>
                        @elseif($cart->contacted)<span class="badge bg-blue-100 text-blue-700">Contacted</span>
                        @else<span class="badge bg-amber-100 text-amber-700">Open</span>@endif
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        @unless($cart->recovered || $cart->contacted)
                            <form action="{{ route('admin.abandoned.contacted', $cart) }}" method="POST" class="inline">@csrf @method('PATCH')<button class="text-xs text-gold-700 hover:underline">Mark contacted</button></form>
                        @endunless
                        <form action="{{ route('admin.abandoned.destroy', $cart) }}" method="POST" class="inline" onsubmit="return confirm('Remove this lead?')">@csrf @method('DELETE')<button class="text-xs text-red-600 hover:underline ml-2">Delete</button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-ink-700/50">No abandoned carts captured yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-6">{{ $carts->links() }}</div>
@endsection
