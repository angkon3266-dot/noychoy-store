@extends('layouts.admin')
@section('title', 'Edit call reminder')
@section('heading', 'Edit call reminder')

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.reminders.index', ['tab' => $reminder->tab()]) }}" class="text-sm text-gold-700 hover:underline">← Back to call reminders</a>
</div>

@if($reminder->isDone())
    {{-- Editing a call already made changes the record of it; it does not
         put the call back on the list. --}}
    <div class="mb-5 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-3 text-sm">
        Marked done by {{ $reminder->closer?->name ?? 'someone no longer on staff' }}
        on {{ \App\Models\CallReminder::exactTime($reminder->done_at) }}@if($reminder->outcome) — {{ $reminder->outcome }}@endif.
        @if($reminder->order)
            <a href="{{ route('admin.orders.show', $reminder->order) }}" class="font-medium underline">Order {{ $reminder->order->order_number }}</a>
        @endif
    </div>
@endif

@include('admin.reminders._form', [
    'formUrl' => route('admin.reminders.update', $reminder),
    'formVerb' => 'PUT',
    'submitLabel' => 'Save changes',
])

{{-- Outside the form above: a form cannot sit inside another. --}}
<form method="POST" action="{{ route('admin.reminders.destroy', $reminder) }}" class="mt-6"
      onsubmit="return confirm('Delete this reminder? This cannot be undone.')">
    @csrf @method('DELETE')
    <button class="text-xs text-red-600 hover:underline">Delete this reminder</button>
</form>
@endsection
