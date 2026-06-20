@extends('layouts.shop')
@section('title', 'My Orders')

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10">
    <h1 class="font-display text-3xl font-semibold mb-6">My orders</h1>
    @include('customer._orders-table', ['orders' => $orders])
</div>
@endsection
