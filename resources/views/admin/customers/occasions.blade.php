@extends('layouts.admin')
@use('App\Support\UpcomingOccasions')
@section('title', 'Birthdays & anniversaries')
@section('heading', 'Birthdays & anniversaries')

@section('content')
{{-- Owner, 2026-09-17: "Create a dashboard for birthdays where I will be able
     to see the customer's name and details for upcoming birthdays,
     anniversaries — show it in the customers section".

     Every date here is a Dhaka day, worked out in App\Support\UpcomingOccasions
     (29 February, New Year and the UTC clock are dealt with there). Rows are
     cards at every width rather than a table: with the sidebar open a tablet
     leaves under 500px for the page, so a table would have scrolled sideways
     long before a phone did. Flash messages come from the layout. --}}
@php
    // Every link names its filters, the defaults included, so a pill never
    // quietly drops the search or the type the owner already chose.
    $occasionsUrl = fn (array $params) => route('admin.customers.occasions', array_filter($params, fn ($value) => $value !== null && $value !== ''));
    $sameYear = $from->year === $to->year && $from->year === $today->year;
    $window = $from->eq($to)
        ? $from->format($sameYear ? 'D j M' : 'D j M Y')
        : $from->format($sameYear ? 'j M' : 'j M Y').' – '.$to->format($sameYear ? 'j M' : 'j M Y');
@endphp

<div class="flex flex-wrap items-center justify-between gap-2 mb-4">
    <a href="{{ route('admin.customers.index') }}" class="text-sm text-gold-700 hover:underline">← All customers</a>
    <p class="text-xs text-ink-700/60">
        @if($automation['enabled'])
            Automatic messages are on: a reminder {{ $automation['reminder_days'] }} {{ Str::plural('day', $automation['reminder_days']) }} before, and a wish on the day.
        @else
            Automatic birthday &amp; anniversary messages are off.
        @endif
        <a href="{{ route('admin.offers.index') }}" class="text-gold-700 hover:underline">Settings →</a>
    </p>
</div>

{{-- The tiles count the whole customer list whatever the filters say, so the
     picture does not change while the owner narrows the list below. --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    @foreach(['today' => 'Today', '7d' => 'Next 7 days', '30d' => 'Next 30 days'] as $key => $label)
        <a href="{{ $occasionsUrl(['range' => $key, 'type' => 'all']) }}" class="card p-4 block hover:bg-gold-50 transition">
            <div class="text-xs text-ink-700/50">{{ $label }}</div>
            <div class="text-2xl font-semibold tabular-nums">{{ number_format($tiles[$key]['total']) }}</div>
            <div class="text-xs text-ink-700/50">
                <span aria-hidden="true">🎂</span> {{ $tiles[$key]['birthday'] }}<span class="sr-only"> {{ Str::plural('birthday', $tiles[$key]['birthday']) }}</span>
                ·
                <span aria-hidden="true">💍</span> {{ $tiles[$key]['anniversary'] }}<span class="sr-only"> {{ Str::plural('anniversary', $tiles[$key]['anniversary']) }}</span>
            </div>
        </a>
    @endforeach
    <div class="card p-4">
        <div class="text-xs text-ink-700/50">Dates on file</div>
        <p class="mt-1 text-sm"><span class="font-semibold tabular-nums">{{ number_format($coverage['birthday']) }} of {{ number_format($coverage['customers']) }}</span> customers have a birthday on file</p>
        <p class="mt-1 text-sm"><span class="font-semibold tabular-nums">{{ number_format($coverage['anniversary']) }} of {{ number_format($coverage['customers']) }}</span> customers have an anniversary on file</p>
    </div>
</div>

<div class="flex flex-wrap items-center gap-x-4 gap-y-2 mb-3">
    <nav class="flex flex-wrap gap-2" aria-label="When">
        @foreach($ranges as $key => $label)
            <a href="{{ $occasionsUrl(['range' => $key, 'type' => $type, 'q' => $q]) }}" @if($range === $key) aria-current="page" @endif
               class="px-3 py-1.5 rounded-full text-sm {{ $range === $key ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700' }}">{{ $label }}</a>
        @endforeach
    </nav>
    <nav class="flex flex-wrap gap-2" aria-label="Occasion">
        @foreach($types as $key => $label)
            <a href="{{ $occasionsUrl(['range' => $range, 'type' => $key, 'q' => $q]) }}" @if($type === $key) aria-current="page" @endif
               class="px-3 py-1.5 rounded-full text-sm {{ $type === $key ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700' }}">{{ $label }}</a>
        @endforeach
    </nav>
</div>

<form method="GET" action="{{ route('admin.customers.occasions') }}" role="search" class="flex flex-wrap items-center gap-2 mb-5">
    <input type="hidden" name="range" value="{{ $range }}">
    <input type="hidden" name="type" value="{{ $type }}">
    <input type="search" name="q" value="{{ $q }}" placeholder="Name or phone…" aria-label="Search by name or phone" class="input py-2 w-56">
    <button class="btn-outline py-2 text-sm">Search</button>
    @if($q !== '')
        <a href="{{ $occasionsUrl(['range' => $range, 'type' => $type]) }}" class="text-sm text-ink-700/60 hover:underline">Clear</a>
    @endif
</form>

{{-- An empty list says all of this in its own card below, so the heading is
     only drawn when there is something to count. --}}
@if($total)
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 mb-3">
        <h2 class="font-semibold">{{ number_format($total) }} {{ $noun }} {{ $phrase }}@if($q !== '') matching “{{ $q }}”@endif</h2>
        <span class="text-xs text-ink-700/50">{{ $window }}</span>
    </div>
@endif

@forelse($groups as $day => $dayRows)
    @php $first = $dayRows->first(); @endphp
    <section class="mb-6" aria-labelledby="occasions-{{ $day }}">
        <h3 id="occasions-{{ $day }}" class="mb-2 flex flex-wrap items-baseline gap-x-2 text-sm font-semibold">
            <span>{{ UpcomingOccasions::dayLabel($first['date'], $today) }}</span>
            @if($first['days_until'] <= 1)
                <span class="text-xs font-normal text-ink-700/50">{{ $first['date']->format('D j M') }}</span>
            @endif
            <span class="text-xs font-normal text-ink-700/50">· {{ $dayRows->count() }}</span>
        </h3>

        <div class="space-y-2">
            @foreach($dayRows as $row)
                @php
                    $c = $row['customer'];
                    $isBirthday = $row['type'] === 'birthday';
                    $rowKey = $row['type'].'-'.$c->id;
                    $reminded = UpcomingOccasions::sentAt($c, $row['type'], 'reminder', $row['date']);
                    $wished = UpcomingOccasions::sentAt($c, $row['type'], 'wish', $row['date']);
                    $tel = tel_link($c->phone);
                    $wa = wa_link($c->phone, UpcomingOccasions::wish($c, $row['type'], $row['days_until']));
                    $smsText = $c->phone ? UpcomingOccasions::wish($c, $row['type'], $row['days_until'], sms: true) : '';
                @endphp
                <article class="card p-4" x-data="occasionSms(@js($smsText))">
                    <div class="grid items-center gap-3 xl:grid-cols-[1fr_320px]">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="badge text-[11px] {{ $isBirthday ? 'bg-pink-100 text-pink-700' : 'bg-violet-100 text-violet-700' }}">{{ $isBirthday ? '🎂 Birthday' : '💍 Anniversary' }}</span>
                                <a href="{{ route('admin.customers.show', $c) }}" class="min-w-0 font-medium text-ink-900 hover:underline [overflow-wrap:anywhere]">{{ $c->name ?: 'Unnamed customer' }}</a>
                                @if($c->isMember())
                                    <span class="badge bg-gold-100 text-gold-800 text-[10px]">Member</span>
                                @else
                                    <span class="badge bg-ink-100 text-ink-700 text-[10px]">Guest</span>
                                @endif
                                @if($c->blacklisted)
                                    <span class="badge bg-red-100 text-red-700 text-[10px]">Blacklisted</span>
                                @endif
                                <span class="ml-auto whitespace-nowrap text-sm {{ $row['days_until'] === 0 ? 'font-semibold text-pink-700' : 'text-ink-700/60' }}">{{ UpcomingOccasions::whenLabel($row['days_until']) }}</span>
                            </div>

                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-ink-700/60">
                                <span>{{ $c->phone ?: 'No phone' }}</span>
                                @if($c->gender)
                                    <span>{{ $c->genderLabel() }}</span>
                                @endif
                                <span>{{ number_format((int) $c->total_orders) }} {{ Str::plural('order', (int) $c->total_orders) }}</span>
                                <span>{{ money($c->total_spent) }} spent</span>
                                <span>{{ $c->last_order_at ? 'Last order '.store_time($c->last_order_at)->format('j M Y') : 'No orders yet' }}</span>
                                <span class="text-gold-700">{{ number_format((int) $c->points) }} pts</span>
                            </div>

                            {{-- What crm:occasions already sent for THIS year's
                                 date — its stamps, read the way it reads them
                                 (UpcomingOccasions::sentAt). A blacklisted
                                 customer never gets one; the badge above says why. --}}
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[11px]">
                                @if($reminded)
                                    <span class="badge bg-green-100 text-green-700 text-[10px]">✓ Reminder sent {{ store_time($reminded)->format('j M') }}</span>
                                @endif
                                @if($wished)
                                    <span class="badge bg-green-100 text-green-700 text-[10px]">✓ Wish sent {{ store_time($wished)->format('j M') }}</span>
                                @endif
                                @unless($reminded || $wished)
                                    <span class="text-ink-700/45">No automatic message sent for this one yet</span>
                                @endunless
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            @if($tel)
                                <a href="{{ $tel }}" class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-200 transition">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.33.57 3.57.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.24.2 2.45.57 3.57a1 1 0 0 1-.25 1.02l-2.2 2.2Z"/></svg>
                                    Call
                                </a>
                            @endif
                            @if($wa)
                                <a href="{{ $wa }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-full bg-green-100 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-200 transition">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24 2.2 0 4.27.86 5.83 2.42a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.79.97-.14.16-.29.18-.54.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.47c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.21-.58.21-1.07.14-1.18-.06-.11-.22-.17-.47-.29Z"/></svg>
                                    WhatsApp
                                </a>
                            @endif
                            @if($c->phone)
                                <button type="button" @click="open = !open" aria-expanded="false" :aria-expanded="open" aria-controls="occasion-sms-{{ $rowKey }}"
                                        class="btn-outline px-3 py-1.5 text-xs">💬 SMS</button>
                            @endif
                            <a href="{{ route('admin.orders.create', ['customer' => $c->id]) }}" class="btn-outline px-3 py-1.5 text-xs whitespace-nowrap">+ New order</a>
                            @if($canRemind)
                                <a href="{{ route('admin.reminders.create', ['customer' => $c->id]) }}" class="btn-outline px-3 py-1.5 text-xs whitespace-nowrap">⏰ Remind me to call</a>
                            @endif
                        </div>
                    </div>

                    {{-- Posts to the customer page's own SMS action, which reads
                         one field, `message`, and sends the owner back here. A
                         field named action/method/target would hijack the
                         background submit — see admin-ajax.js. --}}
                    @if($c->phone)
                        <form id="occasion-sms-{{ $rowKey }}" x-show="open" x-cloak method="POST" action="{{ route('admin.customers.sms', $c) }}"
                              class="mt-3 space-y-2 border-t border-ink-100 pt-3">
                            @csrf
                            <label for="occasion-sms-text-{{ $rowKey }}" class="label text-xs">SMS to {{ $c->phone }} — edit before sending</label>
                            <textarea id="occasion-sms-text-{{ $rowKey }}" name="message" rows="2" maxlength="500" required class="input text-sm" x-model="msg">{{ $smsText }}</textarea>
                            <div class="flex flex-wrap items-center gap-2">
                                <button class="btn-primary py-2 text-sm">Send SMS</button>
                                <button type="button" @click="open = false" class="text-sm text-ink-700/60 hover:underline">Cancel</button>
                                <span class="ml-auto text-xs text-ink-700/50" x-text="cost"></span>
                            </div>
                            @unless($smsReady)
                                <p class="text-xs text-amber-800">
                                    SMS is switched off or missing its credentials, so this will not send.
                                    @if(auth()->user()->canAccess('system-config'))
                                        <a href="{{ route('admin.system-config.integrations') }}" class="underline">Set it up →</a>
                                    @endif
                                </p>
                            @endunless
                        </form>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@empty
    <div class="card px-4 py-10 text-center">
        <p class="text-sm text-ink-700/70">No {{ $noun }} {{ $phrase }}@if($q !== '') matching “{{ $q }}”@endif.</p>
        <p class="mt-1 text-xs text-ink-700/50">{{ $window }}</p>
        @if($coverage['birthday'] + $coverage['anniversary'] === 0)
            <p class="mt-2 text-xs text-ink-700/50">No customer has a birthday or an anniversary on file yet. Customers add them in their account profile.</p>
        @elseif($q !== '')
            <p class="mt-2 text-xs"><a href="{{ $occasionsUrl(['range' => $range, 'type' => $type]) }}" class="text-gold-700 hover:underline">Clear the search</a></p>
        @elseif($range !== '30d' && $range !== 'next-month' && $tiles['30d'][$type === 'all' ? 'total' : $type] > 0)
            <p class="mt-2 text-xs"><a href="{{ $occasionsUrl(['range' => '30d', 'type' => $type]) }}" class="text-gold-700 hover:underline">See the next 30 days →</a></p>
        @endif
    </div>
@endforelse
@endsection

@push('scripts')
<script>
    // One SMS box per row: open or shut, and what the message will cost.
    document.addEventListener('alpine:init', () => {
        Alpine.data('occasionSms', (text) => ({
            open: false,
            msg: text,
            get cost() {
                const length = this.msg.length;
                // A single character outside plain ASCII — any Bangla at all —
                // sends the whole message as unicode: 70 characters to a paid
                // part instead of 160, and 67 / 153 each once it splits.
                const unicode = /[^\x00-\x7F]/.test(this.msg);
                const parts = length <= (unicode ? 70 : 160) ? 1 : Math.ceil(length / (unicode ? 67 : 153));

                return length + ' characters · ' + parts + ' SMS ' + (parts === 1 ? 'part' : 'parts');
            },
        }));
    });
</script>
@endpush
