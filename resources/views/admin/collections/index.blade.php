@extends('layouts.admin')
@section('title', 'Collections')
@section('heading', 'Collections')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif

<div class="flex items-center justify-between mb-4">
    <p class="text-sm text-ink-700/60 max-w-2xl">
        A collection groups products for a page and a menu slot — “Gifts under ৳2,000”, “On sale”, “Eid”.
        A <strong>smart</strong> collection fills itself from rules and keeps up to date on its own;
        a <strong>manual</strong> one is the list you pick. Unlike a category, a product can be in as many as you like.
    </p>
    <a href="{{ route('admin.collections.create') }}" class="btn-primary shrink-0">New collection</a>
</div>

<div class="card overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr>
                <th class="px-4 py-3">Name</th>
                <th class="px-4 py-3">Type</th>
                <th class="px-4 py-3">Products</th>
                <th class="px-4 py-3">In menu</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Order</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody>
        @forelse($collections as $c)
            <tr class="border-t border-ink-100">
                <td class="px-4 py-3">
                    <a href="{{ route('admin.collections.edit', $c) }}" class="font-medium text-gold-700 hover:underline">{{ $c->name }}</a>
                    <div class="text-xs text-ink-700/50"><a href="{{ route('collection.show', $c->slug) }}" target="_blank" rel="noopener">/collection/{{ $c->slug }}</a></div>
                </td>
                <td class="px-4 py-3">
                    <span class="badge {{ $c->isSmart() ? 'bg-gold-100 text-gold-800' : 'bg-ink-100 text-ink-700' }}">{{ $c->isSmart() ? 'Smart' : 'Manual' }}</span>
                </td>
                <td class="px-4 py-3 tabular-nums">
                    {{ $counts[$c->id] ?? 0 }}
                    @if($c->isSmart() && $counts[$c->id] === 0)
                        <span class="text-xs text-red-600 ml-1" title="A smart collection with no matches shows an empty page">— no matches</span>
                    @endif
                </td>
                <td class="px-4 py-3">{{ $c->show_in_menu ? 'Offered' : '—' }}</td>
                <td class="px-4 py-3">
                    <span class="badge {{ $c->is_active ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }}">{{ $c->is_active ? 'Active' : 'Hidden' }}</span>
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <form action="{{ route('admin.collections.move', $c) }}" method="POST" class="inline">@csrf<input type="hidden" name="direction" value="up"><button class="px-1.5 text-ink-700/60 hover:text-ink-900" title="Move up">&uarr;</button></form>
                    <form action="{{ route('admin.collections.move', $c) }}" method="POST" class="inline">@csrf<input type="hidden" name="direction" value="down"><button class="px-1.5 text-ink-700/60 hover:text-ink-900" title="Move down">&darr;</button></form>
                </td>
                <td class="px-4 py-3 text-right">
                    <form action="{{ route('admin.collections.destroy', $c) }}" method="POST" class="inline" onsubmit="return confirm('Delete “{{ $c->name }}”? The products are not touched.')">
                        @csrf @method('DELETE')
                        <button class="text-red-600 hover:underline text-xs">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-4 py-10 text-center text-ink-700/50">
                No collections yet. Start with something you would put in the menu — “Gifts under ৳2,000” or “On sale”.
            </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
