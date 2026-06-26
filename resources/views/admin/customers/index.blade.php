@extends('layouts.admin')
@section('title', 'Customers')
@section('heading', 'Customers')

@section('content')
{{-- Analytics --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="card p-4"><div class="text-xs text-ink-700/50">Total customers</div><div class="text-2xl font-semibold">{{ number_format($analytics['total']) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">Repeat buyers</div><div class="text-2xl font-semibold">{{ number_format($analytics['repeat']) }}</div><div class="text-xs text-ink-700/40">{{ $analytics['total'] ? round($analytics['repeat']/$analytics['total']*100) : 0 }}% of base</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">Avg. spend</div><div class="text-2xl font-semibold">{{ money($analytics['avg_spend']) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">Lifetime revenue</div><div class="text-2xl font-semibold">{{ money($analytics['lifetime']) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">Registered members</div><div class="text-2xl font-semibold">{{ number_format($analytics['members']) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">New this month</div><div class="text-2xl font-semibold">{{ number_format($analytics['new_month']) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">Blacklisted</div><div class="text-2xl font-semibold text-red-600">{{ number_format($analytics['blacklisted']) }}</div></div>
</div>

<form method="GET" class="flex flex-wrap items-end gap-2 mb-4">
    <input name="q" value="{{ request('q') }}" placeholder="Name, phone or email…" class="input py-2 w-56">
    <select name="sort" onchange="this.form.submit()" class="input py-2">
        <option value="spend" @selected($sort=='spend')>Top spenders</option>
        <option value="orders" @selected($sort=='orders')>Most orders</option>
        <option value="recent" @selected($sort=='recent')>Recently ordered</option>
        <option value="points" @selected($sort=='points')>Most points</option>
        <option value="name" @selected($sort=='name')>Name (A–Z)</option>
    </select>
    <input name="min_spend" value="{{ request('min_spend') }}" type="number" min="0" placeholder="Min spend ৳" class="input py-2 w-32">
    <input name="min_orders" value="{{ request('min_orders') }}" type="number" min="0" placeholder="Min orders" class="input py-2 w-28">
    <label class="flex items-center gap-1.5 text-sm px-1"><input type="checkbox" name="repeat" value="1" onchange="this.form.submit()" @checked(request('repeat'))> Repeat</label>
    <label class="flex items-center gap-1.5 text-sm px-1"><input type="checkbox" name="members" value="1" onchange="this.form.submit()" @checked(request('members'))> Members</label>
    <label class="flex items-center gap-1.5 text-sm px-1"><input type="checkbox" name="has_email" value="1" onchange="this.form.submit()" @checked(request('has_email'))> Has email</label>
    <label class="flex items-center gap-1.5 text-sm px-1"><input type="checkbox" name="has_points" value="1" onchange="this.form.submit()" @checked(request('has_points'))> Has points</label>
    <label class="flex items-center gap-1.5 text-sm px-1"><input type="checkbox" name="lapsed" value="1" onchange="this.form.submit()" @checked(request('lapsed'))> Lapsed 30d+</label>
    <button class="btn-outline">Filter</button>
    <a href="{{ route('admin.customers.export', request()->query()) }}" class="btn-outline ml-auto">⬇ Export Excel</a>
    <a href="{{ route('admin.customers.import') }}" class="btn-outline">⬆ Import CSV</a>
</form>

<div class="card overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Orders</th><th class="px-4 py-3">Spent</th><th class="px-4 py-3">Points</th><th class="px-4 py-3">Last order</th><th class="px-4 py-3">Type</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($customers as $c)
                <tr class="cursor-pointer hover:bg-ink-50" onclick="window.location='{{ route('admin.customers.show', $c) }}'">
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ $c->name }} @if($c->blacklisted)<span class="badge bg-red-100 text-red-700 text-[10px]">Blacklisted</span>@endif</div>
                        <div class="text-xs text-ink-700/50">{{ $c->phone }}@if($c->email) · {{ $c->email }}@endif</div>
                    </td>
                    <td class="px-4 py-3">{{ $c->total_orders }}</td>
                    <td class="px-4 py-3">{{ money($c->total_spent) }}</td>
                    <td class="px-4 py-3 text-gold-700">{{ number_format($c->points) }}</td>
                    <td class="px-4 py-3 text-ink-700/60">{{ $c->last_order_at?->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if($c->total_orders > 1)<span class="badge bg-violet-100 text-violet-700 text-[10px]">🔁 Repeat</span>@endif
                        @if($c->password)<span class="badge bg-gold-100 text-gold-800 text-[10px]">Member</span>@else<span class="badge bg-ink-100 text-ink-600 text-[10px]">Guest</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-ink-700/50">No customers found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-6">{{ $customers->links() }}</div>
@endsection
