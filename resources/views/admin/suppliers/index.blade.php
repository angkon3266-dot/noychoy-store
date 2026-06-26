@extends('layouts.admin')
@section('title', 'Suppliers')
@section('heading', 'Suppliers')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif
@if($errors->any())<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm">{{ $errors->first() }}</div>@endif

<div class="grid lg:grid-cols-3 gap-6">
    {{-- Add supplier --}}
    <div class="lg:col-span-1">
        <div class="card p-5 sticky top-4" x-data="{ editing: null }">
            <h2 class="font-semibold mb-3">Add supplier</h2>
            <form action="{{ route('admin.suppliers.store') }}" method="POST" class="space-y-2">
                @csrf
                <input name="name" class="input" placeholder="Supplier name *" required>
                <div class="grid grid-cols-2 gap-2">
                    <input name="contact" class="input" placeholder="Contact person">
                    <input name="country" class="input" value="China" placeholder="Country">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <input name="phone" class="input" placeholder="Phone">
                    <input name="wechat" class="input" placeholder="WeChat / WhatsApp">
                </div>
                <input name="email" type="email" class="input" placeholder="Email">
                <textarea name="notes" rows="2" class="input" placeholder="Notes (MOQ, payment terms…)"></textarea>
                <button class="btn-primary w-full">Add supplier</button>
            </form>
        </div>
    </div>

    {{-- Supplier list --}}
    <div class="lg:col-span-2 space-y-3">
        @forelse($suppliers as $s)
            <div class="card p-4" x-data="{ edit: false }">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="font-medium">{{ $s->name }} <span class="text-xs text-ink-700/50">· {{ $s->country }}</span></div>
                        <div class="text-xs text-ink-700/60 mt-0.5">
                            @if($s->contact){{ $s->contact }} · @endif
                            @if($s->phone){{ $s->phone }} · @endif
                            @if($s->wechat)WeChat {{ $s->wechat }} · @endif
                            @if($s->email){{ $s->email }}@endif
                        </div>
                        @if($s->notes)<p class="text-xs text-ink-700/50 mt-1">{{ $s->notes }}</p>@endif
                        <div class="text-xs text-gold-700 mt-1">{{ $s->purchase_orders_count }} purchase order(s)</div>
                    </div>
                    <div class="shrink-0 flex items-center gap-2">
                        <a href="{{ route('admin.purchase-orders.create', ['supplier' => $s->id]) }}" class="btn-outline text-xs py-1.5">+ PO</a>
                        <button type="button" @click="edit = !edit" class="text-xs text-ink-700/60 hover:underline">Edit</button>
                        <form action="{{ route('admin.suppliers.destroy', $s) }}" method="POST" onsubmit="return confirm('Delete this supplier and its purchase orders?')">
                            @csrf @method('DELETE')
                            <button class="text-xs text-red-600 hover:underline">Delete</button>
                        </form>
                    </div>
                </div>
                <form x-show="edit" x-cloak action="{{ route('admin.suppliers.update', $s) }}" method="POST" class="mt-3 grid grid-cols-2 gap-2 border-t border-ink-100 pt-3">
                    @csrf @method('PUT')
                    <input name="name" value="{{ $s->name }}" class="input" required>
                    <input name="contact" value="{{ $s->contact }}" class="input" placeholder="Contact">
                    <input name="country" value="{{ $s->country }}" class="input" placeholder="Country">
                    <input name="phone" value="{{ $s->phone }}" class="input" placeholder="Phone">
                    <input name="wechat" value="{{ $s->wechat }}" class="input" placeholder="WeChat">
                    <input name="email" value="{{ $s->email }}" class="input" placeholder="Email">
                    <textarea name="notes" rows="2" class="input col-span-2" placeholder="Notes">{{ $s->notes }}</textarea>
                    <button class="btn-primary col-span-2">Save changes</button>
                </form>
            </div>
        @empty
            <div class="card p-8 text-center text-sm text-ink-700/50">No suppliers yet. Add your first supplier on the left.</div>
        @endforelse
    </div>
</div>
@endsection
