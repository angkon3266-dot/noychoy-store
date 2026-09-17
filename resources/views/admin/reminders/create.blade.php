@extends('layouts.admin')
@section('title', 'New call reminder')
@section('heading', 'New call reminder')

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.reminders.index') }}" class="text-sm text-gold-700 hover:underline">← Back to call reminders</a>
</div>

@if($cart)
    {{-- "Remind me to call" on a lead: her number, name and basket are already
         here, and the reminder remembers which lead it came from. --}}
    <div class="mb-5 rounded-xl border-2 border-gold-300 bg-gold-50 px-4 py-3">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 class="font-semibold text-sm">
                From {{ $cart->name ?: $cart->phone ?: 'a saved basket' }}&rsquo;s abandoned cart
            </h2>
            <a href="{{ route('admin.abandoned.show', $cart) }}" class="text-xs text-gold-700 hover:underline">Back to the lead</a>
        </div>
        <p class="mt-1 text-xs text-ink-700/70">
            {{ money($cart->subtotal) }} · {{ $cart->item_count }} item{{ $cart->item_count == 1 ? '' : 's' }}
            · saved {{ $cart->updated_at?->diffForHumans() }}. Choose when to call, then save.
        </p>
    </div>
@endif

@include('admin.reminders._form', [
    'formUrl' => route('admin.reminders.store'),
    'formVerb' => 'POST',
    'submitLabel' => 'Save reminder',
])
@endsection
