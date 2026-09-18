@if($deep['assistantAndSms'] !== null)
    @php
        // Chat & SMS (owner, 2026-09-18: "add other analytical info on the
        // dashboard"). The two paid tools next to what came back from each.
        // Every SMS purpose is counted from the table that owns its outcome
        // (the cart that was texted and recovered, the order that was asked
        // for a review and the review on that phone), never from message
        // text. The assistant's cost is not tracked anywhere yet and the card
        // says so rather than printing a zero; the SMS cost needs the
        // per-segment rate typed once, and links to where.
        $a = $deep['assistantAndSms']['assistant'];
        $sms = $deep['assistantAndSms']['sms'];
        $by = $deep['assistantAndSms']['by_purpose'];
        $pct = fn (?float $v) => $v === null ? '—' : $v.'%';
        $smsQuiet = $sms['sent'] === 0 && $sms['rejected'] === 0;
    @endphp
    <div class="card p-3 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 mb-1 sm:mb-3">
            <h2 class="font-semibold text-sm sm:text-base">Chat &amp; SMS</h2>
            <span class="text-[11px] sm:text-xs text-ink-700/50">{{ $range->label }}</span>
        </div>

        {{-- The assistant. --}}
        <div class="flex items-center justify-between gap-2 mb-1">
            <h3 class="text-xs sm:text-sm font-semibold">Assistant</h3>
            <span class="flex items-center gap-1">
                <span class="badge {{ $a['enabled'] ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }} text-[10px]">{{ $a['enabled'] ? 'on' : 'off' }}</span>
                <span class="badge {{ $a['ordering_enabled'] ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }} text-[10px]">ordering {{ $a['ordering_enabled'] ? 'on' : 'off' }}</span>
            </span>
        </div>
        @if($a['conversations'] === 0)
            <p class="text-[13px] sm:text-sm text-ink-700/50">No conversations in this window.</p>
        @else
            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-[13px] sm:text-sm">
                <div class="flex justify-between gap-2"><span class="text-ink-700/70">Conversations</span><span class="tabular-nums">{{ number_format($a['conversations']) }}</span></div>
                <div class="flex justify-between gap-2">
                    @if(auth()->user()?->canAccess('conversations'))<a href="{{ route('admin.conversations.index', ['filter' => 'failed']) }}" class="text-ink-700/70 hover:text-gold-700">Failed</a>@else<span class="text-ink-700/70">Failed</span>@endif
                    <span class="tabular-nums {{ $a['failed'] > 0 ? 'text-red-600' : '' }}">{{ $a['failed'] }}</span>
                </div>
                <div class="flex justify-between gap-2">
                    @if(auth()->user()?->canAccess('conversations'))<a href="{{ route('admin.conversations.index', ['filter' => 'blocked']) }}" class="text-ink-700/70 hover:text-gold-700">Blocked / junk</a>@else<span class="text-ink-700/70">Blocked / junk</span>@endif
                    <span class="tabular-nums">{{ $a['blocked'] }}</span>
                </div>
                <div class="flex justify-between gap-2"><span class="text-ink-700/70">Product replies</span><span class="tabular-nums">{{ number_format($a['product_replies']) }}</span></div>
            </div>
            <div class="mt-1.5 space-y-0.5 text-[13px] sm:text-sm">
                <div class="flex justify-between gap-2">
                    <span class="text-ink-700/70">Orders from chat</span>
                    <span class="tabular-nums whitespace-nowrap">{{ $a['chat_orders']['count'] }} · {{ money($a['chat_orders']['revenue']) }}</span>
                </div>
                <div class="flex justify-between gap-2 min-w-0">
                    <span class="min-w-0 truncate text-ink-700/70">Chat-assisted</span>
                    <span class="tabular-nums whitespace-nowrap text-right">{{ $a['assisted_orders']['count'] }} <span class="text-ink-700/40 text-[11px] sm:text-xs">({{ $a['assisted_orders']['note'] }}; at least {{ $a['guest_assisted_at_least'] }} guest{{ $a['guest_assisted_at_least'] === 1 ? '' : 's' }})</span></span>
                </div>
            </div>
        @endif
        <p class="text-[11px] text-ink-700/45 mt-1">Cost: {{ $a['cost_tracked'] ? 'tracked' : 'not tracked yet' }}</p>

        {{-- SMS. --}}
        <div class="mt-3 pt-2 border-t border-ink-100 flex items-center justify-between gap-2 mb-1">
            <h3 class="text-xs sm:text-sm font-semibold">SMS</h3>
            @unless($sms['enabled'])
                <span class="badge bg-ink-100 text-ink-700 text-[10px]">SMS is off</span>
            @endunless
        </div>
        @if($smsQuiet)
            <p class="text-[13px] sm:text-sm text-ink-700/50">No SMS sent in this window.</p>
        @else
            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-[13px] sm:text-sm">
                <div class="flex justify-between gap-2"><span class="text-ink-700/70">Sent</span><span class="tabular-nums">{{ number_format($sms['sent']) }}</span></div>
                <div class="flex justify-between gap-2"><span class="text-ink-700/70">Rejected</span><span class="tabular-nums {{ $sms['rejected'] > 0 ? 'text-red-600' : '' }}">{{ number_format($sms['rejected']) }}</span></div>
                <div class="flex justify-between gap-2"><span class="text-ink-700/70">Segments</span><span class="tabular-nums">{{ number_format($sms['segments']) }}</span></div>
                <div class="flex justify-between gap-2 min-w-0">
                    <span class="text-ink-700/70">Cost</span>
                    @if($sms['cost'] === null)
                        @if(auth()->user()?->canAccess('notifications'))<a href="{{ route('admin.notifications.index') }}" class="min-w-0 truncate text-[11px] sm:text-xs text-gold-700 hover:underline">set the per-SMS cost</a>@else<span class="min-w-0 truncate text-[11px] sm:text-xs text-ink-700/50">per-SMS cost not set</span>@endif
                    @else
                        <span class="tabular-nums whitespace-nowrap">{{ money($sms['cost']) }}</span>
                    @endif
                </div>
            </div>
            @if($sms['top_rejection_text'] !== null)
                <p class="text-[11px] text-ink-700/45 mt-0.5 truncate">Most common rejection: {{ $sms['top_rejection_text'] }}</p>
            @endif
        @endif
        <div class="flex justify-between gap-2 mt-1 text-[13px] sm:text-sm">
            <span class="text-ink-700/70">Balance</span>
            <span class="tabular-nums whitespace-nowrap">{{ $sms['balance'] === null ? '—' : money($sms['balance']) }}</span>
        </div>

        @unless($smsQuiet)
            {{-- What each kind of text brought back. --}}
            <div class="mt-2 divide-y divide-ink-100 text-[11px] sm:text-xs tabular-nums">
                <div class="flex justify-between gap-2 py-1 min-w-0">
                    <span class="min-w-0 truncate text-ink-700/70">Cart reminders</span>
                    <span class="shrink-0 whitespace-nowrap">{{ $by['cart_reminders']['sent'] }} sent → {{ $by['cart_reminders']['recovered'] }} recovered <span class="text-ink-700/40">({{ $pct($by['cart_reminders']['recovered_pct']) }})</span> · {{ money($by['cart_reminders']['revenue']) }}</span>
                </div>
                <div class="flex justify-between gap-2 py-1 min-w-0">
                    <span class="min-w-0 truncate text-ink-700/70">Review requests</span>
                    <span class="shrink-0 whitespace-nowrap">{{ $by['review_requests']['sent'] }} sent → {{ $by['review_requests']['replied'] }} replied <span class="text-ink-700/40">({{ $pct($by['review_requests']['pct']) }})</span></span>
                </div>
                <div class="flex justify-between gap-2 py-1 min-w-0">
                    <span class="min-w-0 truncate text-ink-700/70">Occasion texts</span>
                    <span class="shrink-0 whitespace-nowrap">{{ $by['occasion_texts']['sent'] }} sent → {{ $by['occasion_texts']['ordered_within_7d'] }} ordered within 7 d</span>
                </div>
                <div class="flex justify-between gap-2 py-1 min-w-0">
                    <span class="min-w-0 truncate text-ink-700/70">Order texts</span>
                    <span class="shrink-0 whitespace-nowrap">{{ $by['order_texts']['sent'] }} sent</span>
                </div>
                <div class="flex justify-between gap-2 py-1 min-w-0">
                    <span class="min-w-0 truncate text-ink-700/70">Broadcasts</span>
                    <span class="shrink-0 whitespace-nowrap">{{ $by['broadcasts']['sent'] }} sent</span>
                </div>
            </div>
        @endunless
    </div>
@endif
