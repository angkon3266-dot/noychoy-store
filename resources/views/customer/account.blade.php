@extends('layouts.shop')
@section('title', 'My Account')

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10">
    <div class="flex items-center justify-between mb-6">
        <h1 class="font-display text-3xl font-semibold">Hello, {{ $customer->name }}</h1>
        <form action="{{ route('customer.logout') }}" method="POST">@csrf<button class="btn-outline">Log out</button></form>
    </div>

    <div class="grid sm:grid-cols-3 gap-4 mb-8">
        <div class="card p-5"><div class="text-2xl font-semibold text-gold-700">{{ $customer->total_orders }}</div><div class="text-sm text-ink-700/60">Total orders</div></div>
        <div class="card p-5"><div class="text-2xl font-semibold text-gold-700">{{ money($customer->total_spent) }}</div><div class="text-sm text-ink-700/60">Total spent</div></div>
        <div class="card p-5"><div class="text-sm text-ink-700/60">Phone</div><div class="font-medium">{{ $customer->phone }}</div></div>
    </div>

    <div class="flex items-center justify-between mb-4">
        <h2 class="font-display text-xl font-semibold">Recent orders</h2>
        <a href="{{ route('account.orders') }}" class="text-sm text-gold-700 hover:underline">View all →</a>
    </div>
    @include('customer._orders-table', ['orders' => $orders])
</div>
@endsection
