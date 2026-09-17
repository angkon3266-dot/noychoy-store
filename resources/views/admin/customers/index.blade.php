@extends('layouts.admin')
@section('title', 'Customers')
@section('heading', 'Customers')

@section('content')
@php
    $nextBatch = min($courier['batchSize'], $courier['unchecked']);
    $batchRunning = (bool) ($courier['batch']['running'] ?? false);
@endphp
<div class="flex flex-wrap items-center justify-end gap-2 mb-3">
    {{-- The birthdays page is built alongside this one; the button waits for its route. --}}
    @if(\Illuminate\Support\Facades\Route::has('admin.customers.occasions'))
        <a href="{{ route('admin.customers.occasions') }}" class="btn-outline text-sm">🎂 Birthdays &amp; anniversaries</a>
    @endif
    <a href="{{ route('admin.customers.all-offers') }}" class="btn-outline text-sm inline-flex items-center gap-1.5">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/></svg>
        Member offers
    </a>

    {{-- Courier lookups in batches (owner, 2026-09-17). She chose "Batches
         when I click" over checking all 670 customers automatically: every
         lookup is a paid BDCourier credit, so one press looks up at most
         fifty never-checked customers, biggest spenders first, and says what
         it will cost before it starts. --}}
    @if(! $courier['configured'])
        <span class="text-xs text-ink-700/50">BDCourier is not set up — add the API key under Admin → Integrations to check customers.</span>
        <button type="button" class="btn-outline text-sm" disabled>🔍 Check next {{ $courier['batchSize'] }} customers</button>
    @else
        <form action="{{ route('admin.customers.courier-batch') }}" method="POST"
              onsubmit="return confirm('Look up courier history for the next {{ $nextBatch }} customers who have never been checked, biggest spenders first? Uses up to {{ $nextBatch }} BDCourier credits.')">
            @csrf
            <button class="btn-outline text-sm" @disabled($batchRunning || $nextBatch === 0)
                    title="{{ $batchRunning ? 'A batch is already running — wait for it to finish.' : ($nextBatch === 0 ? 'Every customer with a phone number has been checked.' : 'Looks up the next '.$nextBatch.' never-checked customers on BDCourier, one credit each.') }}">
                🔍 Check next {{ $courier['batchSize'] }} customers
                <span class="text-xs font-normal text-ink-700/55 tabular-nums">{{ number_format($courier['unchecked']) }} unchecked</span>
            </button>
        </form>
    @endif
</div>
{{-- Analytics --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="card p-4"><div class="text-xs text-ink-700/50">Total customers</div><div class="text-2xl font-semibold">{{ number_format($analytics['total']) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">Repeat buyers</div><div class="text-2xl font-semibold">{{ number_format($analytics['repeat']) }}</div><div class="text-xs text-ink-700/40">{{ $analytics['total'] ? round($analytics['repeat']/$analytics['total']*100) : 0 }}% of base</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">Avg. spend</div><div class="text-2xl font-semibold">{{ money($analytics['avg_spend']) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">Lifetime revenue</div><div class="text-2xl font-semibold">{{ money($analytics['lifetime']) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">Registered members</div><div class="text-2xl font-semibold">{{ number_format($analytics['members']) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">New this month</div><div class="text-2xl font-semibold">{{ number_format($analytics['new_month']) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-700/50">Blacklisted</div><div class="text-2xl font-semibold text-red-600">{{ number_format($analytics['blacklisted']) }}</div></div>
</div>

<form method="GET" class="flex flex-wrap items-end gap-2 mb-4">
    {{-- Searching from inside a tier stays inside that tier. --}}
    @if($courier['active'])<input type="hidden" name="tier" value="{{ $courier['active'] }}">@endif
    <input name="q" value="{{ request('q') }}" placeholder="Name, phone or email…" class="input py-2 w-56">
    <select name="sort" onchange="submitForm(this.form)" class="input py-2">
        <option value="spend" @selected($sort=='spend')>Top spenders</option>
        <option value="orders" @selected($sort=='orders')>Most orders</option>
        <option value="recent" @selected($sort=='recent')>Recently ordered</option>
        <option value="points" @selected($sort=='points')>Most points</option>
        <option value="parcels" @selected($sort=='parcels')>Most parcels</option>
        <option value="name" @selected($sort=='name')>Name (A–Z)</option>
    </select>
    <input name="min_spend" value="{{ request('min_spend') }}" type="number" min="0" placeholder="Min spend ৳" class="input py-2 w-32">
    <input name="min_orders" value="{{ request('min_orders') }}" type="number" min="0" placeholder="Min orders" class="input py-2 w-28">
    <label class="flex items-center gap-1.5 text-sm px-1"><input type="checkbox" name="repeat" value="1" onchange="submitForm(this.form)" @checked(request('repeat'))> Repeat</label>
    <label class="flex items-center gap-1.5 text-sm px-1"><input type="checkbox" name="members" value="1" onchange="submitForm(this.form)" @checked(request('members'))> Members</label>
    <label class="flex items-center gap-1.5 text-sm px-1"><input type="checkbox" name="has_email" value="1" onchange="submitForm(this.form)" @checked(request('has_email'))> Has email</label>
    <label class="flex items-center gap-1.5 text-sm px-1"><input type="checkbox" name="has_points" value="1" onchange="submitForm(this.form)" @checked(request('has_points'))> Has points</label>
    <label class="flex items-center gap-1.5 text-sm px-1"><input type="checkbox" name="lapsed" value="1" onchange="submitForm(this.form)" @checked(request('lapsed'))> Lapsed 30d+</label>
    <button class="btn-outline">Filter</button>
    <a href="{{ route('admin.customers.export', request()->query()) }}" class="btn-outline ml-auto">⬇ Export Excel</a>
    <a href="{{ route('admin.customers.import') }}" class="btn-outline">⬆ Import CSV</a>
</form>

{{-- Batch progress. A batch runs in the background for a few minutes, so the
     banner asks the server how far it has got every few seconds rather than
     leaving the owner to guess — and stops asking once it is done, or once
     the page it sat on has been swapped away. --}}
@if($batch = $courier['batch'])
    @php
        $bannerTones = [
            'info' => 'border-gold-200 bg-gold-50 text-gold-800',
            'success' => 'border-green-200 bg-green-50 text-green-800',
            'warning' => 'border-amber-200 bg-amber-50 text-amber-800',
            'error' => 'border-red-200 bg-red-50 text-red-800',
        ];
    @endphp
    <div x-data="{
            b: @js($batch),
            tones: @js($bannerTones),
            get toneClasses() {
                return Object.fromEntries(Object.entries(this.tones).map(([tone, classes]) => [classes, this.b.tone === tone]));
            },
            init() { if (this.b.running) this.poll(); },
            poll() {
                setTimeout(async () => {
                    if (! this.$el.isConnected) return;
                    try {
                        const res = await fetch(@js(route('admin.customers.courier-batch.status')), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                        const data = res.ok ? await res.json() : null;
                        if (data && data.id) this.b = data;
                    } catch (e) { /* a blip — the next tick asks again */ }
                    if (this.b.running) this.poll();
                }, 4000);
            },
         }"
         :class="toneClasses"
         class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border px-4 py-2.5 text-sm {{ $bannerTones[$batch['tone']] ?? $bannerTones['info'] }}"
         role="status" aria-live="polite">
        <span x-text="b.message">{{ $batch['message'] }}</span>
        <a href="{{ request()->fullUrl() }}" x-show="b.finished" @style(['display: none' => ! $batch['finished']])
           class="font-medium underline">Refresh the list</a>
    </div>
@endif

{{-- Courier value slicer (owner, 2026-09-17): "categorise them by colour code
     too and add a slicer on the customer section to quickly navigate".

     Tiered by the TOTAL parcels a number has sent with every courier, the
     owner's pick. Counts come from one grouped query over whatever the
     filters above are showing, but never the tier itself — a pill keeps its
     own total while another is on. Each pill carries the rest of the query
     string, so a click never drops the search, the sort or a ticked box. --}}
@php
    $pillQuery = request()->except(['tier', 'page']);
    $pillBase = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-3 py-1.5 text-sm font-medium transition hover:opacity-90';
    $pillOn = 'ring-2 ring-gold-500 shadow-sm';
    $tierCounts = $courier['counts'];
    $activeTier = $courier['active'];
@endphp
<div class="mb-4 flex flex-wrap items-center gap-2">
    <span class="mr-1 text-xs font-medium uppercase tracking-wide text-ink-700/50">Courier parcels</span>
    <a href="{{ route('admin.customers.index', $pillQuery) }}" @if($activeTier === null) aria-current="true" @endif
       class="{{ $pillBase }} border-ink-200 bg-white text-ink-700 {{ $activeTier === null ? $pillOn : '' }}">
        All <span class="tabular-nums opacity-70">{{ number_format($tierCounts['all']) }}</span>
    </a>
    <a href="{{ route('admin.customers.index', $pillQuery + ['tier' => \App\Support\CourierTier::UNCHECKED]) }}"
       @if($activeTier === \App\Support\CourierTier::UNCHECKED) aria-current="true" @endif
       title="Customers with a phone number that has never been looked up"
       class="{{ $pillBase }} border-dashed border-ink-300 bg-white text-ink-700/70 {{ $activeTier === \App\Support\CourierTier::UNCHECKED ? $pillOn : '' }}">
        Not checked <span class="tabular-nums opacity-70">{{ number_format($tierCounts['unchecked']) }}</span>
    </a>
    @foreach($courier['tiers'] as $key => $t)
        <a href="{{ route('admin.customers.index', $pillQuery + ['tier' => $key]) }}" @if($activeTier === $key) aria-current="true" @endif
           title="{{ $t['max'] === null ? number_format($t['min']).' or more' : number_format($t['min']).' to '.number_format($t['max'] - 1) }} parcels with all couriers"
           class="{{ $pillBase }} border-transparent {{ $t['badge'] }} {{ $activeTier === $key ? $pillOn : '' }}">
            {{ $t['label'] }} <span class="tabular-nums opacity-70">{{ number_format($tierCounts[$key]) }}</span>
        </a>
    @endforeach
    @if($tierCounts['no-phone'] > 0)
        <span class="text-xs text-ink-700/45">· {{ number_format($tierCounts['no-phone']) }} without a phone number</span>
    @endif
</div>

<div x-data="{ sel: [], showOffer: false }">
    {{-- Bulk personalised-offer bar --}}
    <div x-show="sel.length" x-cloak class="mb-4 rounded-lg border border-gold-200 bg-gold-50 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm font-medium"><span x-text="sel.length"></span> selected</span>
            <button type="button" @click="showOffer = !showOffer" class="btn-primary py-2 text-sm">🎁 Apply personalised offer</button>
            <button type="button" @click="sel = []" class="text-sm text-ink-700/60 hover:underline ml-auto">Clear</button>
        </div>
        <form x-show="showOffer" x-cloak action="{{ route('admin.customers.bulk-offer') }}" method="POST" class="mt-3 grid sm:grid-cols-2 gap-2 border-t border-gold-200 pt-3">
            @csrf
            <template x-for="id in sel" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <input name="title" class="input" placeholder="Offer title (e.g. VIP 10% off) *" required>
            <input name="description" class="input" placeholder="Short description (optional)">
            <select name="type" class="input">
                @foreach(\App\Models\CustomerOffer::TYPES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
            <input name="value" type="number" step="0.01" class="input" placeholder="Value (% / ৳ / points)">
            <input name="code" class="input" placeholder="Code (optional)">
            <input name="expires_at" type="date" class="input">
            <textarea name="message" rows="2" class="input sm:col-span-2" placeholder="Message (optional) — used in the web push and SMS. Empty = auto-written from the title & reward."></textarea>
            <div class="sm:col-span-2 flex flex-wrap items-center gap-5 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" name="send_push" value="1" checked> 🔔 Send web push + bell notification</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="send_sms" value="1"> 💬 Also send SMS <span class="text-xs text-ink-700/50">(uses SMS credits)</span></label>
            </div>
            <button class="btn-primary sm:col-span-2">Apply offer to <span x-text="sel.length"></span> customer(s)</button>
        </form>
    </div>

<div class="card overflow-x-auto">
    <table class="w-full min-w-[720px] text-sm">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr><th class="px-3 py-3 w-8"></th><th class="px-4 py-3">Customer</th><th class="px-4 py-3" title="Parcels this number has sent with every courier (BDCourier), and how many were delivered">Courier</th><th class="px-4 py-3">Orders</th><th class="px-4 py-3">Spent</th><th class="px-4 py-3">Points</th><th class="px-4 py-3">Last order</th><th class="px-4 py-3">Type</th><th class="px-4 py-3"><span class="sr-only">Actions</span></th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($customers as $c)
                <tr class="cursor-pointer hover:bg-ink-50" onclick="window.location='{{ route('admin.customers.show', $c) }}'">
                    <td class="px-3 py-3" onclick="event.stopPropagation()"><input type="checkbox" value="{{ $c->id }}" x-model.number="sel"></td>
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ $c->name }} @if($c->blacklisted)<span class="badge bg-red-100 text-red-700 text-[10px]">Blacklisted</span>@endif</div>
                        <div class="text-xs text-ink-700/50">{{ $c->phone }}@if($c->email) · {{ $c->email }}@endif</div>
                    </td>
                    {{-- The tier, in its colour, with the parcel count, and the
                         delivered rate beside it. Read from the stored lookup
                         only; "Check" is the one thing here that spends a
                         credit, and only when pressed. --}}
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if($check = $c->courierCheck)
                            @php
                                $tier = \App\Support\CourierTier::forTotal((int) $check->total_parcel);
                                $delivery = \App\Support\CourierTier::delivery($check->success_ratio, (int) $check->total_parcel);
                            @endphp
                            <div class="flex items-center gap-1.5">
                                <span class="badge {{ $tier['badge'] }} text-[10px] tabular-nums" title="{{ $tier['label'] }} parcels">{{ number_format($check->total_parcel) }} parcels</span>
                                <span class="badge border bg-white {{ $delivery['classes'] }} text-[10px]" title="{{ $delivery['note'] }}">{{ $delivery['label'] }}</span>
                            </div>
                            @unless($check->isFresh(\App\Services\BdCourierService::FRESH_HOURS))
                                <div class="mt-0.5 text-[10px] text-ink-700/40" title="{{ store_time($check->checked_at)?->format('j M Y') }}">checked {{ $check->checked_at?->diffForHumans() }}</div>
                            @endunless
                        @elseif(blank($c->phone))
                            <span class="text-xs text-ink-700/35">No phone</span>
                        @else
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-ink-700/40">Not checked</span>
                                @if($courier['configured'])
                                    {{-- stopPropagation: the row itself opens the customer. --}}
                                    <form action="{{ route('admin.customers.courier-check', $c) }}" method="POST" onclick="event.stopPropagation()">
                                        @csrf
                                        <button class="rounded-full border border-ink-200 bg-white px-2 py-0.5 text-[11px] font-medium text-ink-700 hover:border-gold-300 hover:bg-gold-50"
                                                title="Look this number up on BDCourier — uses one credit">Check</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $c->total_orders }}</td>
                    <td class="px-4 py-3">{{ money($c->total_spent) }}</td>
                    <td class="px-4 py-3 text-gold-700">{{ number_format($c->points) }}</td>
                    <td class="px-4 py-3 text-ink-700/60">{{ $c->last_order_at?->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if($c->total_orders > 1)<span class="badge bg-violet-100 text-violet-700 text-[10px]">🔁 Repeat</span>@endif
                        @if($c->password)<span class="badge bg-gold-100 text-gold-800 text-[10px]">Member</span>@else<span class="badge bg-ink-100 text-ink-600 text-[10px]">Guest</span>@endif
                    </td>
                    {{-- A repeat buyer on the phone, straight into the order form
                         with her details filled in (owner, 2026-09-17: "add
                         option so I can create new order from customer list").
                         The click stops here so the row does not also open the
                         customer page underneath it. Blacklisted customers keep
                         the button; the form warns before anything is saved. --}}
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.orders.create', ['customer' => $c->id]) }}" onclick="event.stopPropagation()"
                           class="btn-outline py-1 text-xs whitespace-nowrap">+ New order</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-10 text-center text-ink-700/50">No customers found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-6">{{ $customers->links() }}</div>
</div>
@endsection
