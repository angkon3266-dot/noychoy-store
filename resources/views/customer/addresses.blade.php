@extends('layouts.shop')
@section('title', 'My addresses')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8 md:py-10">
    <div class="grid md:grid-cols-[220px_1fr] gap-8">
        <aside class="hidden md:block"><div class="card p-3 sticky top-20">@include('customer._nav')</div></aside>

        <div class="min-w-0 max-w-2xl">
            @include('customer._flash')
            <div class="flex items-center justify-between mb-6">
                <h1 class="font-display text-2xl font-semibold">My addresses</h1>
            </div>

            {{-- Existing addresses --}}
            <div class="space-y-3 mb-6">
                @forelse($addresses as $a)
                    <div class="card p-4" x-data="{ edit: false }">
                        <div x-show="!edit" class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ $a->name }}</span>
                                    @if($a->label)<span class="badge bg-ink-50 text-ink-700">{{ $a->label }}</span>@endif
                                    @if($a->is_default)<span class="badge bg-gold-100 text-gold-800">Default</span>@endif
                                </div>
                                <p class="text-sm text-ink-700/70">{{ $a->phone }}</p>
                                <p class="text-sm text-ink-700/70">{{ collect([$a->address, $a->area, $a->district])->filter()->implode(', ') }}</p>
                            </div>
                            <div class="flex flex-col items-end gap-1 shrink-0 text-sm">
                                <button @click="edit = true" class="text-gold-700 hover:underline">Edit</button>
                                @unless($a->is_default)
                                    <form action="{{ route('account.addresses.default', $a) }}" method="POST">@csrf<button class="text-ink-700/70 hover:underline">Set default</button></form>
                                    <form action="{{ route('account.addresses.delete', $a) }}" method="POST" onsubmit="return confirm('Remove this address?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Delete</button></form>
                                @endunless
                            </div>
                        </div>

                        {{-- Edit form --}}
                        <form x-show="edit" x-cloak action="{{ route('account.addresses.update', $a) }}" method="POST" class="space-y-3">
                            @csrf @method('PATCH')
                            @include('customer._address-fields', ['a' => $a])
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="edit = false" class="btn-outline text-sm py-1.5">Cancel</button>
                                <button class="btn-primary text-sm py-1.5">Save</button>
                            </div>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-ink-700/60">No saved addresses yet.</p>
                @endforelse
            </div>

            {{-- Add new --}}
            <div class="card p-5" x-data="{ open: {{ $addresses->isEmpty() ? 'true' : 'false' }} }">
                <button @click="open = !open" type="button" class="flex w-full items-center justify-between font-semibold">
                    + Add a new address
                    <svg class="w-5 h-5 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <form x-show="open" x-collapse action="{{ route('account.addresses.store') }}" method="POST" class="space-y-3 mt-4">
                    @csrf
                    @include('customer._address-fields', ['a' => null])
                    <div class="flex justify-end"><button class="btn-primary">Save address</button></div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
