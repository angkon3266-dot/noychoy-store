@extends('layouts.admin')
@section('title', $customer->name)
@section('heading', 'Customer · '.$customer->name)

@section('content')
<a href="{{ route('admin.customers.index') }}" class="text-sm text-gold-700 hover:underline">← All customers</a>

<div class="grid lg:grid-cols-3 gap-6 mt-4">
    <div class="lg:col-span-2 space-y-6">
        {{-- Summary --}}
        <div class="grid grid-cols-3 gap-3">
            <div class="card p-4"><div class="text-xs text-ink-700/50">Orders</div><div class="text-2xl font-semibold">{{ $customer->total_orders }}</div></div>
            <div class="card p-4"><div class="text-xs text-ink-700/50">Lifetime spend</div><div class="text-2xl font-semibold">{{ money($customer->total_spent) }}</div></div>
            <div class="card p-4"><div class="text-xs text-ink-700/50">Avg. order</div><div class="text-2xl font-semibold">{{ money($customer->total_orders ? $customer->total_spent / $customer->total_orders : 0) }}</div></div>
        </div>

        {{-- Delivery reliability --}}
        @if($insight)
            @php
                $risk = ['none'=>['bg-ink-100 text-ink-700','No delivery history yet'],'low'=>['bg-green-100 text-green-700','Reliable'],'medium'=>['bg-amber-100 text-amber-700','Some failed deliveries'],'high'=>['bg-red-100 text-red-700','High COD risk']][$insight['risk']];
            @endphp
            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-semibold">Delivery track record</h2>
                    <span class="badge {{ $risk[0] }}">{{ $risk[1] }}</span>
                </div>
                <div class="grid grid-cols-4 gap-3 text-center text-sm">
                    <div><div class="text-xl font-semibold text-green-700">{{ $insight['delivered'] }}</div><div class="text-xs text-ink-700/50">Delivered</div></div>
                    <div><div class="text-xl font-semibold text-red-600">{{ $insight['cancelled'] }}</div><div class="text-xs text-ink-700/50">Cancelled</div></div>
                    <div><div class="text-xl font-semibold text-amber-600">{{ $insight['returned'] }}</div><div class="text-xs text-ink-700/50">Returned</div></div>
                    <div><div class="text-xl font-semibold">{{ $insight['success_rate'] ?? '—' }}@if($insight['success_rate'] !== null)%@endif</div><div class="text-xs text-ink-700/50">Success</div></div>
                </div>
            </div>
        @endif

        {{-- Orders --}}
        <div class="card overflow-hidden">
            <h2 class="font-semibold p-4 pb-0">Order history</h2>
            <table class="w-full text-sm mt-2">
                <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                    <tr><th class="px-4 py-2">Order</th><th class="px-4 py-2">Total</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Date</th></tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @forelse($orders as $o)
                        <tr class="hover:bg-ink-50 cursor-pointer" onclick="window.location='{{ route('admin.orders.show', $o) }}'">
                            <td class="px-4 py-2 font-medium text-gold-700">{{ $o->order_number }}</td>
                            <td class="px-4 py-2">{{ money($o->total) }}</td>
                            <td class="px-4 py-2"><span class="badge bg-gold-100 text-gold-800 capitalize">{{ $o->status }}</span></td>
                            <td class="px-4 py-2 text-ink-700/60">{{ $o->created_at->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-ink-700/50">No orders yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Contact / edit --}}
        <div class="card p-5">
            <h2 class="font-semibold mb-3">Details</h2>
            <form action="{{ route('admin.customers.update', $customer) }}" method="POST" class="space-y-3">
                @csrf @method('PUT')
                <div><label class="label">Name</label><input name="name" value="{{ old('name', $customer->name) }}" class="input" required></div>
                <div><label class="label">Phone</label><input value="{{ $customer->phone ?? '—' }}" class="input bg-ink-50" disabled></div>
                <div><label class="label">Email</label><input name="email" value="{{ old('email', $customer->email) }}" class="input"></div>
                <div><label class="label">Internal notes</label><textarea name="notes" rows="3" class="input">{{ old('notes', $customer->notes) }}</textarea></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="blacklisted" value="1" @checked($customer->blacklisted)> Blacklist (high-risk COD)</label>
                <button class="btn-primary w-full">Save</button>
            </form>
        </div>

        {{-- Send SMS --}}
        <div class="card p-5">
            <h2 class="font-semibold mb-3">Send SMS</h2>
            <form action="{{ route('admin.customers.sms', $customer) }}" method="POST" class="space-y-3">
                @csrf
                <textarea name="message" rows="3" class="input" placeholder="Type a message to {{ $customer->phone }}…" maxlength="500" required></textarea>
                <button class="btn-outline w-full" {{ $customer->phone ? '' : 'disabled' }}>Send SMS</button>
            </form>
        </div>
    </div>
</div>
@endsection
