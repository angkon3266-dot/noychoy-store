@extends('layouts.admin')
@section('title', 'Lead · '.($cart->name ?: $cart->phone))
@section('heading', $cart->name ?: 'Abandoned cart')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route('admin.abandoned.index') }}" class="text-sm text-gold-700 hover:underline">← Back to abandoned carts</a>

    <div class="flex flex-wrap items-center gap-2">
        @if($telLink)
            <a href="{{ $telLink }}"
               class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 transition"
               title="{{ $cart->phone }}">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.33.57 3.57.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.24.2 2.45.57 3.57a1 1 0 0 1-.25 1.02l-2.2 2.2Z"/>
                </svg>
                Call
            </a>
        @endif
        @if($waLink)
            <a href="{{ $waLink }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700 transition">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24 2.2 0 4.27.86 5.83 2.42a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.79.97-.14.16-.29.18-.54.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.47c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.21-.58.21-1.07.14-1.18-.06-.11-.22-.17-.47-.29Z"/>
                </svg>
                WhatsApp
            </a>
        @endif
        <form action="{{ route('admin.abandoned.sms', $cart) }}" method="POST" class="inline"
              onsubmit="return confirm('Text her the cart link now? This spends SMS credit.')">
            @csrf
            <button class="btn-outline py-2 text-sm" @disabled(! $smsReady || $cart->recovered)>💬 Send SMS</button>
        </form>
    </div>
</div>

@if($cart->recovered)
    <div class="card p-4 mt-4 border-green-200 bg-green-50 text-sm text-green-800">
        This cart was recovered — an order came through on this number after the checkout was abandoned.
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mt-5">
    {{-- ── Left: what she left behind, and the link that brings it back ── --}}
    <div class="lg:col-span-2 space-y-5">
        <div class="card p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-semibold">Cart left at {{ $cart->stepLabel() }}</h2>
                <span class="text-sm text-ink-700/60">{{ $cart->item_count }} item(s)</span>
            </div>

            <div class="divide-y divide-ink-100">
                @forelse($lines as $line)
                    <div class="flex items-center gap-3 py-3">
                        <div class="h-14 w-14 shrink-0 overflow-hidden rounded-md bg-ink-50">
                            @if($line['thumb'])
                                <img src="{{ $line['thumb'] }}" alt="" class="h-full w-full object-cover">
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            @if($line['url'])
                                <a href="{{ $line['url'] }}" target="_blank" rel="noopener" class="font-medium hover:underline">{{ $line['name'] }}</a>
                            @else
                                <span class="font-medium">{{ $line['name'] }}</span>
                            @endif
                            @if($line['variant'])<div class="text-xs text-ink-700/60">{{ $line['variant'] }}</div>@endif
                            <div class="text-xs text-ink-700/60">{{ money($line['price']) }} × {{ $line['qty'] }}</div>
                            @unless($line['available'])
                                <span class="badge bg-red-100 text-red-700 text-[10px] mt-1">No longer available — the restore link will skip this line</span>
                            @endunless
                        </div>
                        <div class="text-right font-medium whitespace-nowrap">{{ money($line['total']) }}</div>
                    </div>
                @empty
                    <p class="py-3 text-sm text-ink-700/50">The cart snapshot is empty.</p>
                @endforelse
            </div>

            <div class="mt-3 flex items-center justify-between border-t border-ink-100 pt-3">
                <span class="text-sm text-ink-700/70">Cart subtotal when she left</span>
                <span class="text-lg font-semibold">{{ money($cart->subtotal) }}</span>
            </div>
        </div>

        {{-- The link is the whole point of the follow-up: it rebuilds this exact
             cart on her phone so she does not have to hunt for the pieces again. --}}
        <div class="card p-5" x-data="{ copied: false }">
            <h2 class="font-semibold mb-1">Her cart link</h2>
            <p class="text-xs text-ink-700/60 mb-2">
                Signed and it does not expire. Opening it puts these pieces back in her cart.
                The WhatsApp and SMS buttons already include it.
            </p>
            <div class="flex flex-wrap gap-2">
                <input readonly value="{{ $restoreLink }}" class="input py-2 font-mono text-xs flex-1 min-w-0" onclick="this.select()">
                <button type="button" class="btn-outline py-2 text-sm whitespace-nowrap"
                        @click="navigator.clipboard.writeText('{{ $restoreLink }}').then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                    <span x-show="!copied">Copy link</span>
                    <span x-show="copied" x-cloak class="text-green-700">Copied ✓</span>
                </button>
            </div>
            <details class="mt-3">
                <summary class="text-xs text-ink-700/60 cursor-pointer">Preview the WhatsApp message</summary>
                <p class="mt-2 whitespace-pre-wrap rounded-md bg-ink-50 p-3 text-xs text-ink-700/80">{{ $waMessage }}</p>
                <p class="mt-1 text-[11px] text-ink-700/45">
                    Edit this wording under <a href="{{ route('admin.system-config.integrations') }}" class="underline">Settings → Integrations</a>.
                </p>
            </details>
        </div>

        {{-- ── Follow-up log ── --}}
        <div class="card p-5">
            <h2 class="font-semibold mb-3">Follow-up history</h2>

            <form action="{{ route('admin.abandoned.log', $cart) }}" method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-2 mb-4">
                @csrf
                <div>
                    <label class="label text-xs" for="channel">What did you do?</label>
                    <select name="channel" id="channel" class="input py-2">
                        @foreach(\App\Models\AbandonedCartContact::CHANNELS as $key => $label)
                            @continue($key === 'sms')
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label text-xs" for="outcome">How did it go?</label>
                    <select name="outcome" id="outcome" class="input py-2">
                        <option value="">—</option>
                        @foreach(\App\Models\AbandonedCartContact::OUTCOMES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label text-xs" for="note">Note</label>
                    <input name="note" id="note" class="input py-2" placeholder="Call back after 8pm…" maxlength="2000">
                </div>
                <div class="sm:col-span-3">
                    <button class="btn-primary py-2 text-sm">Log follow-up</button>
                </div>
            </form>

            @forelse($cart->contacts as $contact)
                <div class="flex items-start gap-3 border-t border-ink-100 py-3">
                    <span class="badge {{ $contact->channelBadgeClass() }} shrink-0">{{ $contact->channelLabel() }}</span>
                    <div class="min-w-0 flex-1">
                        @if($contact->outcomeLabel())<div class="text-sm font-medium">{{ $contact->outcomeLabel() }}</div>@endif
                        @if($contact->note)<p class="text-sm text-ink-700/80">{{ $contact->note }}</p>@endif
                        <p class="text-[11px] text-ink-700/45 mt-0.5">
                            {{ $contact->user?->name ?? 'System' }} · {{ store_time($contact->created_at)?->format('j M Y, g:ia') }}
                        </p>
                    </div>
                </div>
            @empty
                <p class="border-t border-ink-100 pt-3 text-sm text-ink-700/50">Nobody has followed up yet.</p>
            @endforelse
        </div>
    </div>

    {{-- ── Right: who she is ── --}}
    <div class="space-y-5">
        <div class="card p-5 text-sm">
            <h2 class="font-semibold mb-3">Contact</h2>
            <dl class="space-y-2">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-700/50">Name</dt>
                    <dd>{{ $cart->name ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-700/50">Phone</dt>
                    <dd><a href="{{ $telLink }}" class="font-medium text-gold-700">{{ $cart->phone }}</a></dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-700/50">Email</dt>
                    <dd>{{ $cart->email ?: 'Not collected at checkout' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-700/50">Delivery address</dt>
                    <dd class="whitespace-pre-wrap">{{ $cart->address ?: 'Not typed before she left' }}</dd>
                </div>
                @if($cart->area)
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-700/50">Area / Thana</dt>
                        <dd>{{ $cart->area }}</dd>
                    </div>
                @endif
                @if($cart->zoneLabel())
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-700/50">Delivery zone</dt>
                        <dd>{{ $cart->zoneLabel() }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <div class="card p-5 text-sm">
            <div class="flex items-center justify-between mb-2">
                <h2 class="font-semibold">This number</h2>
                @if($insight['is_repeat'] ?? false)
                    <span class="badge bg-violet-100 text-violet-700">🔁 Repeat ({{ $insight['total'] }} {{ \Illuminate\Support\Str::plural('order', $insight['total']) }})</span>
                @else
                    <span class="badge bg-ink-100 text-ink-700">First-time</span>
                @endif
            </div>

            @if(($insight['total'] ?? 0) > 0)
                <p class="text-ink-700/70">
                    {{ $insight['delivered'] }} delivered · {{ $insight['pending'] }} in flight ·
                    {{ $insight['cancelled'] }} cancelled · {{ $insight['returned'] }} returned
                </p>
                @if(($insight['risk'] ?? 'none') !== 'none')
                    <p class="mt-1 text-xs text-amber-700">
                        Cash-on-delivery risk: <strong>{{ ucfirst($insight['risk']) }}</strong>
                        ({{ $insight['success_rate'] }}% of parcels arrived).
                    </p>
                @endif
            @else
                <p class="text-ink-700/60">No orders on this number yet — this would be her first.</p>
            @endif

            @if($customer)
                <div class="mt-3 border-t border-ink-100 pt-3 space-y-1">
                    <p><span class="text-ink-700/60">Account:</span> {{ $customer->isMember() ? 'Registered member' : 'Guest record' }}</p>
                    <p><span class="text-ink-700/60">Lifetime spend:</span> {{ money($customer->total_spent) }}</p>
                    @if($customer->blacklisted)
                        <p class="badge bg-red-100 text-red-700">Blacklisted</p>
                    @endif
                    <a href="{{ route('admin.customers.show', $customer) }}" class="text-xs text-gold-700 hover:underline">Open customer →</a>
                </div>
            @endif
        </div>

        <div class="card p-5 text-sm">
            <h2 class="font-semibold mb-2">Where she came from</h2>
            @php $channel = $attribution['source_channel'] ?? null; @endphp
            @if($channel)
                @php $src = \App\Support\TrafficSource::class; @endphp
                <span class="badge {{ $src::badgeClass($channel) }}">{{ $src::label($channel) }}</span>
                @if($attribution['source_campaign'] ?? null)
                    <p class="mt-1 text-ink-700/70">Campaign: <strong>{{ $attribution['source_campaign'] }}</strong></p>
                @endif
                @if($attribution['landing_path'] ?? null)
                    <p class="mt-1 text-xs text-ink-700/45">Landed on {{ \Illuminate\Support\Str::start(ltrim($attribution['landing_path'], '/'), '/') }}</p>
                @endif
            @else
                <p class="text-ink-700/50">
                    Not recorded — this lead was captured before the visitor token was stored on the cart.
                </p>
            @endif
        </div>

        <div class="card p-5 text-sm">
            <h2 class="font-semibold mb-2">Timeline</h2>
            <ul class="space-y-1.5 text-ink-700/70">
                <li>Cart captured {{ store_time($cart->created_at)?->format('j M Y, g:ia') }}</li>
                <li>Last activity {{ $cart->updated_at->diffForHumans() }}</li>
                <li>{{ $cart->sms_reminded_at ? 'Recovery SMS sent '.$cart->sms_reminded_at->diffForHumans() : 'No recovery SMS sent' }}</li>
                <li>{{ $cart->last_contacted_at ? 'Last followed up '.$cart->last_contacted_at->diffForHumans() : 'Never followed up' }}</li>
            </ul>

            <form action="{{ route('admin.abandoned.destroy', $cart) }}" method="POST" class="mt-4 border-t border-ink-100 pt-3"
                  onsubmit="return confirm('Remove this lead? This cannot be undone.')">
                @csrf @method('DELETE')
                <button class="text-xs text-red-600 hover:underline">Delete this lead</button>
            </form>
        </div>
    </div>
</div>
@endsection
