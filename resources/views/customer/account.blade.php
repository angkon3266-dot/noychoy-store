@extends('layouts.shop')
@section('title', 'My Account')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8 md:py-10">
    <div class="grid md:grid-cols-[220px_1fr] gap-8">
        {{-- Sidebar (desktop) --}}
        <aside class="hidden md:block">
            <div class="card p-3 sticky top-20">@include('customer._nav')</div>
        </aside>

        <div class="min-w-0">
            @include('customer._flash')

            <div class="flex items-center justify-between mb-6">
                <div>
                    <p class="text-sm text-ink-700/60">Welcome back,</p>
                    <h1 class="font-display text-2xl md:text-3xl font-semibold">{{ $customer->name }}</h1>
                </div>
            </div>

            {{-- Summary --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-8">
                <a href="{{ route('account.orders') }}" class="card p-4 hover:border-gold-300 transition">
                    <div class="text-2xl font-semibold text-gold-700">{{ $customer->total_orders }}</div>
                    <div class="text-xs text-ink-700/60">Orders</div>
                </a>
                <div class="card p-4">
                    <div class="text-2xl font-semibold text-gold-700">{{ money($customer->total_spent) }}</div>
                    <div class="text-xs text-ink-700/60">Total spent</div>
                </div>
                <a href="{{ route('account.loved') }}" class="card p-4 hover:border-gold-300 transition">
                    <div class="text-2xl font-semibold text-red-500">{{ $lovedCount }}</div>
                    <div class="text-xs text-ink-700/60">Loved items</div>
                </a>
                <a href="{{ route('account.reviews') }}" class="card p-4 hover:border-gold-300 transition">
                    <div class="text-2xl font-semibold text-gold-700">{{ $reviewCount }}</div>
                    <div class="text-xs text-ink-700/60">Reviews</div>
                </a>
            </div>

            {{-- Default address --}}
            <div class="card p-5 mb-8">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="font-semibold">Default shipping address</h2>
                    <a href="{{ route('account.addresses') }}" class="text-sm text-gold-700 hover:underline">Manage →</a>
                </div>
                @if($defaultAddress)
                    <p class="text-sm text-ink-800">{{ $defaultAddress->name }} · {{ $defaultAddress->phone }}</p>
                    <p class="text-sm text-ink-700/70">{{ collect([$defaultAddress->address, $defaultAddress->area, $defaultAddress->district])->filter()->implode(', ') }}</p>
                @else
                    <p class="text-sm text-ink-700/60">No saved address yet. <a href="{{ route('account.addresses') }}" class="text-gold-700 hover:underline">Add one</a> for faster checkout.</p>
                @endif
            </div>

            {{-- Recent orders --}}
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-display text-xl font-semibold">Recent orders</h2>
                <a href="{{ route('account.orders') }}" class="text-sm text-gold-700 hover:underline">View all →</a>
            </div>
            @forelse($orders as $order)
                <div class="card p-4 mb-3 flex flex-wrap items-center gap-3">
                    <div class="flex-1 min-w-0">
                        <a href="{{ route('account.order', $order->order_number) }}" class="font-medium text-gold-700 hover:underline">#{{ $order->order_number }}</a>
                        <span class="text-xs text-ink-700/50 ml-2">{{ $order->created_at->format('d M Y') }}</span>
                        <div class="text-sm text-ink-700/70">{{ money($order->total) }} · <span class="badge bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span></div>
                    </div>
                    <form action="{{ route('account.reorder', $order->order_number) }}" method="POST">@csrf
                        <button class="btn-outline text-sm py-1.5">Reorder</button>
                    </form>
                </div>
            @empty
                <div class="card p-6 text-center text-sm text-ink-700/60">
                    No orders yet. <a href="{{ route('shop') }}" class="text-gold-700 hover:underline">Start shopping →</a>
                </div>
            @endforelse
        </div>
    </div>
</div>
@endsection
