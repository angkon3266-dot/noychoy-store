@extends('layouts.admin')
@section('title', 'Customer groups')
@section('heading', 'Customer groups')

@php
    $toneMap = [
        'green' => 'bg-green-50 border-green-200 text-green-800',
        'gold' => 'bg-gold-50 border-gold-200 text-gold-800',
        'amber' => 'bg-amber-50 border-amber-200 text-amber-800',
        'red' => 'bg-red-50 border-red-200 text-red-700',
    ];
@endphp

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif
@if(session('error'))<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm">{{ session('error') }}</div>@endif

<p class="text-sm text-ink-700/70 mb-5 max-w-3xl">
    Build groups of customers by spend, orders, gender, location or activity (auto-updating), or by hand-picking members.
    Then message a group from <a href="{{ route('admin.notifications.index') }}" class="text-gold-700 underline">Notifications</a>, or grant it an offer.
</p>

{{-- ── RFM auto-segments overview ─────────────────────────────────────────── --}}
<div class="card p-6 mb-6">
    <div class="flex items-start justify-between flex-wrap gap-2 mb-1">
        <h2 class="font-semibold">Smart segments (RFM)</h2>
        <span class="text-xs text-ink-700/50">Recency &amp; frequency of orders · updates automatically</span>
    </div>
    <p class="text-sm text-ink-700/60 mb-4 max-w-3xl">Every customer with an order is auto-sorted into one tier. Turn any tier into a saved group in one click, then message it or grant it an offer.</p>
    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-3">
        @foreach($rfm as $b)
            <div class="rounded-xl border px-4 py-3 {{ $toneMap[$b['tone']] ?? 'bg-ink-50 border-ink-200' }}">
                <div class="flex items-baseline justify-between">
                    <span class="font-semibold">{{ $b['emoji'] }} {{ $b['label'] }}</span>
                    <span class="text-lg font-bold tabular-nums">{{ number_format($b['count']) }}</span>
                </div>
                <p class="text-[11px] leading-snug mt-1 opacity-80">{{ $b['blurb'] }}</p>
                <p class="text-[11px] mt-1 opacity-70">Revenue: {{ money($b['revenue']) }}</p>
                <form action="{{ route('admin.segments.store') }}" method="POST" class="mt-2">
                    @csrf
                    <input type="hidden" name="name" value="{{ $b['label'] }}">
                    <input type="hidden" name="type" value="dynamic">
                    <input type="hidden" name="rules[rfm]" value="{{ $b['key'] }}">
                    <button class="text-xs font-medium underline hover:no-underline" {{ $b['count'] === 0 ? 'disabled' : '' }}>Save as group →</button>
                </form>
            </div>
        @endforeach
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    {{-- Builder --}}
    <div class="card p-6 h-fit"
         x-data="{
            type: '{{ old('type', $editing->type ?? 'dynamic') }}',
            rules: @js((object) ($editing->rules ?? [])),
            memberQ: '',
            count: null, loading: false,
            async preview() {
                if (this.type !== 'dynamic') return;
                this.loading = true;
                try {
                    const r = await fetch('{{ route('admin.segments.preview') }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', Accept: 'application/json' },
                        body: JSON.stringify({ rules: this.rules }),
                    });
                    this.count = (await r.json()).count;
                } catch (e) { this.count = null; }
                this.loading = false;
            }
         }"
         x-init="preview()">
        <h2 class="font-semibold mb-4">{{ $editing ? 'Edit group' : 'New group' }}</h2>
        @if($errors->any())<div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mb-3">{{ $errors->first() }}</div>@endif
        <form action="{{ $editing ? route('admin.segments.update', $editing) : route('admin.segments.store') }}" method="POST" class="space-y-3">
            @csrf
            @if($editing) @method('PUT') @endif
            <div><label class="label">Group name *</label><input name="name" value="{{ old('name', $editing->name ?? '') }}" class="input" placeholder="VIP spenders" required></div>
            <div>
                <label class="label">Group type</label>
                <select name="type" x-model="type" class="input">
                    <option value="dynamic">Automatic (by rules — updates itself)</option>
                    <option value="manual">Manual (hand-pick members)</option>
                </select>
            </div>

            {{-- Dynamic rules --}}
            <div x-show="type==='dynamic'" @input.debounce.500ms="preview()" @change.debounce.500ms="preview()" class="space-y-2">
                <div><label class="label text-xs">Smart tier (RFM)</label>
                    <select name="rules[rfm]" x-model="rules.rfm" class="input py-1.5 text-sm">
                        <option value="">Any</option>
                        @foreach($rfmBuckets as $k => $b)<option value="{{ $k }}">{{ $b['emoji'] }} {{ $b['label'] }}</option>@endforeach
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="rules[members_only]" value="1" x-model="rules.members_only"> Registered members only</label>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label text-xs">Min. spent ৳</label><input type="number" name="rules[min_spend]" x-model="rules.min_spend" class="input py-1.5 text-sm" placeholder="any"></div>
                    <div><label class="label text-xs">Max. spent ৳</label><input type="number" name="rules[max_spend]" x-model="rules.max_spend" class="input py-1.5 text-sm" placeholder="any"></div>
                    <div><label class="label text-xs">Min. orders</label><input type="number" name="rules[min_orders]" x-model="rules.min_orders" class="input py-1.5 text-sm" placeholder="any"></div>
                    <div><label class="label text-xs">Gender</label>
                        <select name="rules[gender]" x-model="rules.gender" class="input py-1.5 text-sm">
                            <option value="">Any</option>
                            @foreach($genders as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div><label class="label text-xs">Location (area / district / city contains)</label><input name="rules[location]" x-model="rules.location" class="input py-1.5 text-sm" placeholder="e.g. Dhaka"></div>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label text-xs">Activity</label>
                        <select name="rules[activity]" x-model="rules.activity" class="input py-1.5 text-sm">
                            <option value="any">Any</option>
                            <option value="active">Ordered recently</option>
                            <option value="lapsed">Lapsed (hasn't ordered)</option>
                        </select>
                    </div>
                    <div><label class="label text-xs">Within (days)</label><input type="number" name="rules[activity_days]" x-model="rules.activity_days" class="input py-1.5 text-sm" placeholder="60"></div>
                </div>
                <div class="rounded-lg bg-gold-50 border border-gold-200 px-3 py-2 text-sm">
                    <span x-show="loading" class="text-ink-700/50">Counting…</span>
                    <span x-show="!loading"><strong x-text="count === null ? '—' : count"></strong> customer(s) match</span>
                </div>
            </div>

            {{-- Manual member picker --}}
            <div x-show="type==='manual'" x-cloak>
                <input x-model="memberQ" placeholder="Filter customers…" class="input py-1.5 text-sm mb-2">
                <div class="max-h-56 overflow-y-auto rounded-lg border border-ink-100 p-2 space-y-1">
                    @php
                        $chosen = $editing ? $editing->members->pluck('id')->all() : [];
                    @endphp
                    @foreach($allCustomers as $cust)
                        <label class="flex items-center gap-2 text-sm" x-show="memberQ==='' || '{{ Str::lower(addslashes($cust->name)).' '.$cust->phone }}'.includes(memberQ.toLowerCase())">
                            <input type="checkbox" name="member_ids[]" value="{{ $cust->id }}" @checked(in_array($cust->id, $chosen))>
                            {{ $cust->name }} <span class="text-xs text-ink-700/40">{{ $cust->phone }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex gap-2 pt-1">
                <button class="btn-primary flex-1">{{ $editing ? 'Save group' : 'Create group' }}</button>
                @if($editing)<a href="{{ route('admin.segments.index') }}" class="btn-outline">Cancel</a>@endif
            </div>
        </form>
    </div>

    {{-- List --}}
    <div class="lg:col-span-2 card overflow-x-auto"
         x-data="{
            offer: { id: null, name: '' },
            base: '{{ route('admin.segments.grant-offer', '__ID__') }}',
            open(id, name) { this.offer = { id, name }; },
            get action() { return this.base.replace('__ID__', this.offer.id); }
         }">
        <table class="w-full min-w-[560px] text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Group</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Members</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($segments as $seg)
                    <tr class="hover:bg-ink-50">
                        <td class="px-4 py-3 font-medium">{{ $seg->name }}</td>
                        <td class="px-4 py-3 text-ink-700/60">{{ $seg->type === 'manual' ? 'Manual' : 'Automatic' }}</td>
                        <td class="px-4 py-3">{{ number_format($seg->member_count) }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button type="button" @click="open({{ $seg->id }}, @js($seg->name))" class="text-gold-700 hover:underline">Grant offer</button>
                            <a href="{{ route('admin.segments.index', ['edit' => $seg->id]) }}" class="text-gold-700 hover:underline ml-2">Edit</a>
                            <form action="{{ route('admin.segments.destroy', $seg) }}" method="POST" class="inline" onsubmit="return confirm('Delete this group?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline ml-2">Delete</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-ink-700/50">No groups yet. Create one on the left.</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- Grant-offer modal --}}
        <div x-show="offer.id" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(0,0,0,.4)" @keydown.escape.window="offer.id=null">
            <div class="card p-6 w-full max-w-md max-h-[90vh] overflow-y-auto" @click.outside="offer.id=null">
                <h3 class="font-semibold mb-1">Grant an offer</h3>
                <p class="text-xs text-ink-700/60 mb-4">To everyone in <strong x-text="offer.name"></strong>. Each member gets it on their dashboard and it auto-applies at checkout.</p>
                <form :action="action" method="POST" class="space-y-3" x-data="{ otype: 'percent' }">
                    @csrf
                    <div><label class="label text-xs">Offer title *</label><input name="title" class="input py-1.5 text-sm" placeholder="A little something for you" required></div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label text-xs">Type *</label>
                            <select name="type" x-model="otype" class="input py-1.5 text-sm">
                                @foreach($offerTypes as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
                            </select>
                        </div>
                        <div x-show="otype!=='free_shipping'"><label class="label text-xs" x-text="otype==='percent' ? 'Percent %' : (otype==='points' ? 'Points' : 'Amount ৳')">Value</label><input type="number" step="0.01" name="value" class="input py-1.5 text-sm" placeholder="0"></div>
                    </div>
                    <div><label class="label text-xs">Message (optional — shown to member / SMS)</label><textarea name="message" rows="2" class="input py-1.5 text-sm" placeholder="Enjoy 10% off your next order 💛"></textarea></div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label text-xs">Code (optional)</label><input name="code" class="input py-1.5 text-sm" placeholder="auto-applies"></div>
                        <div><label class="label text-xs">Expires (optional)</label><input type="datetime-local" name="expires_at" class="input py-1.5 text-sm"></div>
                    </div>
                    <div><label class="label text-xs">Max uses per member (blank = unlimited until expiry)</label><input type="number" name="max_redemptions" min="1" class="input py-1.5 text-sm" placeholder="unlimited"></div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="send_sms" value="1"> Also send the message by SMS <span class="text-xs text-ink-700/50">(uses credits)</span></label>
                    <div class="flex gap-2 pt-1">
                        <button class="btn-primary flex-1">Grant to group</button>
                        <button type="button" class="btn-outline" @click="offer.id=null">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
