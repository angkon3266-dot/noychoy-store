@extends('layouts.admin')
@section('title', 'Purchase orders')
@section('heading', 'Purchase orders')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="card p-4"><div class="text-xs text-ink-700/50">Open POs</div><div class="text-2xl font-semibold">{{ $summary['open'] }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">Open value</div><div class="text-2xl font-semibold">{{ number_format($summary['value_open'], 2) }}</div></div>
</div>

<form method="GET" class="flex flex-wrap gap-2 mb-4">
    <select name="status" onchange="this.form.submit()" class="input py-2">
        <option value="">All statuses</option>
        @foreach($statuses as $key => $label)
            <option value="{{ $key }}" @selected(request('status')==$key)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="supplier" onchange="this.form.submit()" class="input py-2">
        <option value="">All suppliers</option>
        @foreach($suppliers as $s)
            <option value="{{ $s->id }}" @selected(request('supplier')==$s->id)>{{ $s->name }}</option>
        @endforeach
    </select>
    <a href="{{ route('admin.purchase-orders.create') }}" class="btn-primary ml-auto">+ New purchase order</a>
</form>

<div class="card overflow-x-auto">
    <table class="w-full min-w-[720px] text-sm">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr><th class="px-4 py-3">PO #</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3">Items</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Ordered</th><th class="px-4 py-3">Expected</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($orders as $po)
                <tr class="cursor-pointer hover:bg-ink-50" onclick="window.location='{{ route('admin.purchase-orders.show', $po) }}'">
                    <td class="px-4 py-3 font-medium text-gold-700">{{ $po->po_number }}</td>
                    <td class="px-4 py-3">{{ $po->supplier->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $po->items_count }}</td>
                    <td class="px-4 py-3">{{ $po->currency }} {{ number_format($po->total_cost, 2) }}</td>
                    <td class="px-4 py-3"><span class="badge capitalize {{ $po->status === 'received' ? 'bg-green-100 text-green-700' : ($po->status === 'cancelled' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ $po->statusLabel() }}</span></td>
                    <td class="px-4 py-3 text-ink-700/60">{{ $po->ordered_at?->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-3 text-ink-700/60">{{ $po->expected_at?->format('d M Y') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-10 text-center text-ink-700/50">No purchase orders yet. <a href="{{ route('admin.purchase-orders.create') }}" class="text-gold-700 hover:underline">Create one</a>.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-6">{{ $orders->links() }}</div>
@endsection
