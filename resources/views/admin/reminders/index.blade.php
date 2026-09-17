@extends('layouts.admin')
@section('title', 'Call reminders')
@section('heading', 'Call reminders')

@section('content')
{{-- Calls to make later (owner, 2026-09-17: "create a reminder option where I
     can add customer lead phone number along with items to call later").

     Every control here is a link or a plain form. admin-ajax.js swaps <main>
     in place after a POST and never runs a <script> that arrives with it, so
     nothing on this page may lean on a function defined in a script of its
     own: the Alpine state below is inline object literals only.

     Flash messages are rendered once by layouts/admin.blade.php. --}}
@php
    $canCustomers = auth()->user()->canAccess('customers');
    $canLeads = auth()->user()->canAccess('abandoned');
    $canOrders = auth()->user()->canAccess('orders');
@endphp

<p class="text-sm text-ink-700/70 mb-4">
    Numbers to ring back, and what they asked about. A reminder moves into Due now — and into the
    notification bell — the moment its time comes.
</p>

@if($errors->any())
    <div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">
        <ul class="list-disc list-inside">
            @foreach($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="flex flex-wrap items-center gap-2 mb-4">
    <a href="{{ route('admin.reminders.create') }}" class="btn-primary py-2 text-sm">+ New reminder</a>
    <form method="GET" class="flex flex-wrap gap-2">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input name="q" value="{{ $q }}" placeholder="Name or phone…" class="input py-2 w-56" aria-label="Search reminders by name or phone">
        <button class="btn-outline py-2 text-sm">Search</button>
        @if($q)<a href="{{ route('admin.reminders.index', ['tab' => $tab]) }}" class="btn-outline py-2 text-sm">Clear</a>@endif
    </form>
    <div class="ml-auto flex flex-wrap gap-2">
        {{-- Every tab names itself, the default included, exactly as the
             abandoned-cart filters do. --}}
        @foreach($tabs as $key => $label)
            <a href="{{ route('admin.reminders.index', array_filter(['tab' => $key, 'q' => $q ?: null])) }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm {{ $tab === $key ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700' }}"
               @if($tab === $key) aria-current="page" @endif>
                {{ $label }}
                @if($key === 'due' && ($counts['due'] ?? 0) > 0)
                    <span class="min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-semibold inline-flex items-center justify-center">{{ $counts['due'] }}</span>
                @elseif($key === 'upcoming' && ($counts['upcoming'] ?? 0) > 0)
                    <span class="text-xs opacity-70">{{ $counts['upcoming'] }}</span>
                @endif
            </a>
        @endforeach
    </div>
</div>

<div class="card divide-y divide-ink-100">
    @forelse($reminders as $reminder)
        @php
            $done = $reminder->isDone();
            $due = $reminder->isDue();
            $overdue = $reminder->isOverdue();
            $name = $reminder->displayName();
            $items = $reminder->itemSummary($variantLabels);
            $tel = tel_link($reminder->phone);
            $wa = $reminder->whatsappLink();
        @endphp
        <div class="p-4 grid gap-3 lg:grid-cols-5 {{ $overdue ? 'bg-red-50' : '' }}"
             x-data="{ panel: '' }" @keydown.escape="panel = ''">

            {{-- Who, and what about --}}
            <div class="min-w-0 space-y-1 lg:col-span-2">
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span class="font-medium">{{ $name ?: $reminder->phone }}</span>
                    @if($name)<span class="text-sm text-ink-700/60">{{ $reminder->phone }}</span>@endif
                    @if($reminder->customer?->blacklisted)
                        <span class="badge bg-red-100 text-red-700 text-[10px]">Blacklisted</span>
                    @endif
                    @if($reminder->customer && $canCustomers)
                        <a href="{{ route('admin.customers.show', $reminder->customer) }}" class="text-xs text-gold-700 hover:underline">Customer →</a>
                    @endif
                    @if($reminder->abandoned_cart_id && $canLeads)
                        <a href="{{ route('admin.abandoned.show', $reminder->abandoned_cart_id) }}" class="text-xs text-gold-700 hover:underline">Abandoned cart →</a>
                    @endif
                </div>

                @if($items !== '')
                    <p class="text-sm text-ink-700/80">{{ $items }}</p>
                @endif
                @if($reminder->notes)
                    <p class="text-sm text-ink-700/70 whitespace-pre-line">{{ $reminder->notes }}</p>
                @endif

                @if($done)
                    <p class="text-sm">
                        <span class="font-medium text-green-700">Done</span>@if($reminder->outcome) — {{ $reminder->outcome }}@endif
                    </p>
                    <p class="text-[11px] text-ink-700/45">
                        {{ $reminder->closer?->name ?? 'Someone no longer on staff' }} · {{ \App\Models\CallReminder::exactTime($reminder->done_at) }}
                        @if($reminder->order)
                            · <a href="{{ route('admin.orders.show', $reminder->order) }}" class="text-gold-700 hover:underline">Order {{ $reminder->order->order_number }}</a>
                        @endif
                    </p>
                @else
                    <p class="text-[11px] text-ink-700/45">
                        Added by {{ $reminder->creator?->name ?? 'someone no longer on staff' }} · {{ \App\Models\CallReminder::exactTime($reminder->created_at) }}
                    </p>
                @endif
            </div>

            {{-- When, in the shop's own clock --}}
            <div class="lg:col-span-1">
                <div class="text-sm font-medium {{ $overdue ? 'text-red-700' : '' }}">{{ $reminder->dueLabel() }}</div>
                <div class="text-[11px] text-ink-700/50">{{ $reminder->dueExact() }}</div>
                @if($overdue)
                    <span class="badge bg-red-100 text-red-700 text-[10px] mt-1">Overdue · {{ $reminder->lateBy() }} late</span>
                @elseif($due)
                    <span class="badge bg-amber-100 text-amber-700 text-[10px] mt-1">Due now</span>
                @endif
            </div>

            {{-- Reach them, and settle the reminder --}}
            <div class="flex flex-wrap items-center gap-1.5 self-start lg:col-span-2">
                @if($tel)
                    <a href="{{ $tel }}"
                       class="shrink-0 grid h-8 w-8 place-items-center rounded-full bg-blue-100 text-blue-700 hover:bg-blue-200 transition"
                       title="Call {{ $reminder->phone }}" aria-label="Call {{ $name ?: $reminder->phone }}">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.33.57 3.57.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.24.2 2.45.57 3.57a1 1 0 0 1-.25 1.02l-2.2 2.2Z"/>
                        </svg>
                    </a>
                @endif
                @if($wa)
                    <a href="{{ $wa }}" target="_blank" rel="noopener"
                       class="shrink-0 grid h-8 w-8 place-items-center rounded-full bg-green-100 text-green-700 hover:bg-green-200 transition"
                       title="WhatsApp {{ $name ?: $reminder->phone }}" aria-label="WhatsApp {{ $name ?: $reminder->phone }}">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24 2.2 0 4.27.86 5.83 2.42a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.79.97-.14.16-.29.18-.54.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.47c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.21-.58.21-1.07.14-1.18-.06-.11-.22-.17-.47-.29Z"/>
                        </svg>
                    </a>
                @endif

                @unless($done)
                    {{-- The order form opens with this number and these items
                         in it, and saving the order ticks this reminder off. --}}
                    @if($canOrders)
                        <a href="{{ route('admin.orders.create', ['reminder' => $reminder->id]) }}" class="btn-primary py-1.5 px-3 text-xs">Create order</a>
                    @endif
                    @if($due)
                        <button type="button" class="btn-outline py-1.5 px-3 text-xs"
                                @click="panel = panel === 'snooze' ? '' : 'snooze'" :aria-expanded="panel === 'snooze'">Snooze</button>
                    @endif
                    <button type="button" class="btn-outline py-1.5 px-3 text-xs"
                            @click="panel = panel === 'done' ? '' : 'done'; $nextTick(() => panel === 'done' && $refs.outcome.focus())"
                            :aria-expanded="panel === 'done'">Mark done</button>
                @endunless

                <a href="{{ route('admin.reminders.edit', $reminder) }}" class="px-1 text-xs text-gold-700 hover:underline">Edit</a>
                {{-- A plain onsubmit: Alpine discards the return value of an
                     @submit handler, so "return confirm()" there would delete
                     on Cancel. admin-ajax.js leaves a prevented submit alone. --}}
                <form method="POST" action="{{ route('admin.reminders.destroy', $reminder) }}" class="inline"
                      onsubmit="return confirm('Delete this reminder? This cannot be undone.')">
                    @csrf @method('DELETE')
                    <button class="px-1 text-xs text-red-600 hover:underline">Delete</button>
                </form>

                @if($due)
                    {{-- Worked out on the server in Dhaka time. "This evening"
                         is not offered once 7 PM has gone. --}}
                    <div x-show="panel === 'snooze'" x-cloak class="w-full rounded-md border border-ink-100 bg-ink-50 p-2">
                        <form method="POST" action="{{ route('admin.reminders.snooze', $reminder) }}" class="flex flex-wrap items-center gap-1.5">
                            @csrf
                            <span class="mr-1 text-xs text-ink-700/60">Call again:</span>
                            @foreach(\App\Models\CallReminder::SNOOZES as $key => $label)
                                @continue($key === 'evening' && ! $eveningAhead)
                                <button name="until" value="{{ $key }}" class="btn-outline bg-white py-1 px-2.5 text-xs">{{ $label }}</button>
                            @endforeach
                        </form>
                    </div>
                @endif

                @unless($done)
                    <div x-show="panel === 'done'" x-cloak class="w-full rounded-md border border-ink-100 bg-ink-50 p-2">
                        <form method="POST" action="{{ route('admin.reminders.done', $reminder) }}" class="flex flex-wrap items-center gap-1.5">
                            @csrf
                            <label class="sr-only" for="outcome-{{ $reminder->id }}">What came of the call</label>
                            <input id="outcome-{{ $reminder->id }}" name="outcome" maxlength="500" x-ref="outcome"
                                   placeholder="What came of it? (optional)" class="input py-1.5 text-sm flex-1 min-w-0">
                            <button class="btn-primary py-1.5 px-3 text-xs">Mark done</button>
                        </form>
                    </div>
                @endunless
            </div>
        </div>
    @empty
        <div class="px-4 py-10 text-center text-sm text-ink-700/50">
            @if($q)
                No reminders under “{{ $tabs[$tab] }}” match that search.
            @elseif($tab === 'due')
                Nothing is due right now.
                @if(($counts['upcoming'] ?? 0) > 0)
                    <a href="{{ route('admin.reminders.index', ['tab' => 'upcoming']) }}" class="text-gold-700 hover:underline">{{ $counts['upcoming'] }} upcoming →</a>
                @endif
            @elseif($tab === 'upcoming')
                No calls scheduled. <a href="{{ route('admin.reminders.create') }}" class="text-gold-700 hover:underline">Add a reminder →</a>
            @else
                Nothing marked done yet.
            @endif
        </div>
    @endforelse
</div>

<div class="mt-6">{{ $reminders->links() }}</div>
@endsection
