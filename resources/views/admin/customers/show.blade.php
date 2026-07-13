@extends('layouts.admin')
@section('title', $customer->name)
@section('heading', 'Customer · '.$customer->name)

@section('content')
<a href="{{ route('admin.customers.index') }}" class="text-sm text-gold-700 hover:underline">← All customers</a>

<div class="grid lg:grid-cols-3 gap-6 mt-4">
    <div class="lg:col-span-2 space-y-6">
        {{-- Summary --}}
        <div class="grid grid-cols-3 gap-3">
            <div class="card p-4"><div class="text-xs text-ink-700/50">Orders</div><div class="text-2xl font-semibold">{{ $customer->total_orders }}</div></div>
            <div class="card p-4"><div class="text-xs text-ink-700/50">Lifetime spend</div><div class="text-2xl font-semibold">{{ money($customer->total_spent) }}</div></div>
            <div class="card p-4"><div class="text-xs text-ink-700/50">Avg. order</div><div class="text-2xl font-semibold">{{ money($customer->total_orders ? $customer->total_spent / $customer->total_orders : 0) }}</div></div>
        </div>

        {{-- Delivery reliability --}}
        @if($insight)
            @php
                $risk = ['none'=>['bg-ink-100 text-ink-700','No delivery history yet'],'low'=>['bg-green-100 text-green-700','Reliable'],'medium'=>['bg-amber-100 text-amber-700','Some failed deliveries'],'high'=>['bg-red-100 text-red-700','High COD risk']][$insight['risk']];
            @endphp
            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-semibold">Delivery track record</h2>
                    <span class="badge {{ $risk[0] }}">{{ $risk[1] }}</span>
                </div>
                <div class="grid grid-cols-4 gap-3 text-center text-sm">
                    <div><div class="text-xl font-semibold text-green-700">{{ $insight['delivered'] }}</div><div class="text-xs text-ink-700/50">Delivered</div></div>
                    <div><div class="text-xl font-semibold text-red-600">{{ $insight['cancelled'] }}</div><div class="text-xs text-ink-700/50">Cancelled</div></div>
                    <div><div class="text-xl font-semibold text-amber-600">{{ $insight['returned'] }}</div><div class="text-xs text-ink-700/50">Returned</div></div>
                    <div><div class="text-xl font-semibold">{{ $insight['success_rate'] ?? '—' }}@if($insight['success_rate'] !== null)%@endif</div><div class="text-xs text-ink-700/50">Success</div></div>
                </div>
            </div>
        @endif

        {{-- Orders --}}
        <div class="card overflow-hidden">
            <h2 class="font-semibold p-4 pb-0">Order history</h2>
            <table class="w-full text-sm mt-2">
                <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                    <tr><th class="px-4 py-2">Order</th><th class="px-4 py-2">Total</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Date</th></tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @forelse($orders as $o)
                        <tr class="hover:bg-ink-50 cursor-pointer" onclick="window.location='{{ route('admin.orders.show', $o) }}'">
                            <td class="px-4 py-2 font-medium text-gold-700">{{ $o->order_number }}</td>
                            <td class="px-4 py-2">{{ money($o->total) }}</td>
                            <td class="px-4 py-2"><span class="badge bg-gold-100 text-gold-800 capitalize">{{ $o->status }}</span></td>
                            <td class="px-4 py-2 text-ink-700/60">{{ $o->created_at->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-ink-700/50">No orders yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Contact / edit --}}
        <div class="card p-5">
            <h2 class="font-semibold mb-3">Details</h2>
            <form action="{{ route('admin.customers.update', $customer) }}" method="POST" class="space-y-3">
                @csrf @method('PUT')
                <div><label class="label">Name</label><input name="name" value="{{ old('name', $customer->name) }}" class="input" required></div>
                <div><label class="label">Phone</label><input value="{{ $customer->phone ?? '—' }}" class="input bg-ink-50" disabled></div>
                <div><label class="label">Email</label><input name="email" value="{{ old('email', $customer->email) }}" class="input"></div>
                <div><label class="label">Internal notes</label><textarea name="notes" rows="3" class="input">{{ old('notes', $customer->notes) }}</textarea></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="blacklisted" value="1" @checked($customer->blacklisted)> Blacklist (high-risk COD)</label>
                <button class="btn-primary w-full">Save</button>
            </form>
        </div>

        {{-- Loyalty points --}}
        <div class="card p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-semibold">Loyalty points</h2>
                <span class="text-lg font-semibold text-gold-700">{{ $customer->points }} pts</span>
            </div>
            <p class="text-xs text-ink-700/50 mb-3">Lifetime earned: {{ $customer->points_lifetime }} · worth {{ money(app(\App\Services\LoyaltyService::class)->pointsValue((int) $customer->points)) }}</p>
            <form action="{{ route('admin.customers.points', $customer) }}" method="POST" class="flex items-end gap-2">
                @csrf
                <div class="flex-1"><label class="label">Adjust (+/−)</label><input name="points" type="number" class="input" placeholder="e.g. 100 or -50" required></div>
                <input name="reason" class="input flex-1" placeholder="Reason (optional)">
                <button class="btn-outline">Apply</button>
            </form>
            @if($pointLog->isNotEmpty())
                <details class="mt-3">
                    <summary class="text-xs text-gold-700 cursor-pointer">Recent points activity</summary>
                    <ul class="mt-2 space-y-1 text-xs text-ink-700/70">
                        @foreach($pointLog as $tx)
                            <li class="flex justify-between gap-2">
                                <span>{{ $tx->description ?: $tx->type }} <span class="text-ink-700/40">· {{ $tx->created_at->format('d M') }}</span></span>
                                <span class="{{ $tx->points >= 0 ? 'text-green-700' : 'text-red-600' }}">{{ $tx->points >= 0 ? '+' : '' }}{{ $tx->points }}</span>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>

        {{-- Personalised offers --}}
        <div class="card p-5">
            <h2 class="font-semibold mb-3">Personalised offers</h2>
            @forelse($offers as $offer)
                <div class="flex items-start justify-between gap-2 border-b border-ink-100 py-2 text-sm">
                    <div class="min-w-0">
                        <p class="font-medium">{{ $offer->title }} <span class="text-xs text-ink-700/50">· {{ $offer->rewardText() }}</span></p>
                        @if($offer->code)<p class="text-xs font-mono text-gold-700">{{ $offer->code }}</p>@endif
                        <p class="text-xs text-ink-700/50">{{ $offer->scopeLabel() }}@if($offer->applies_to==='categories' && $offer->category_ids) · {{ count($offer->category_ids) }} categor{{ count($offer->category_ids)===1?'y':'ies' }}@elseif($offer->applies_to==='products' && $offer->product_ids) · {{ count($offer->product_ids) }} product(s)@endif</p>
                        @if($offer->message)<p class="text-xs text-ink-700/60 italic">“{{ $offer->message }}”</p>@endif
                        <p class="text-xs {{ $offer->isLive() ? 'text-green-700' : 'text-ink-700/40' }}">{{ $offer->isLive() ? 'Live' : ($offer->redeemed_at ? 'Redeemed '.$offer->redeemed_at->format('d M Y') : 'Inactive/expired') }}@if($offer->expires_at) · until {{ $offer->expires_at->format('d M Y') }}@endif</p>
                    </div>
                    <form action="{{ route('admin.customers.offers.destroy', [$customer, $offer]) }}" method="POST" onsubmit="return confirm('Remove this offer?')">
                        @csrf @method('DELETE')
                        <button class="text-red-600 text-xs hover:underline">Remove</button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-ink-700/50 mb-3">No personalised offers yet.</p>
            @endforelse

            <form action="{{ route('admin.customers.offers.store', $customer) }}" method="POST" class="space-y-2 mt-3"
                  x-data="{ applies: 'all', catQ: '', prodQ: '' }">
                @csrf
                <input name="title" class="input" placeholder="Offer title (e.g. VIP 15% off)" required>
                <input name="description" class="input" placeholder="Short description (optional)">
                <div class="grid grid-cols-2 gap-2">
                    <select name="type" class="input">
                        @foreach(\App\Models\CustomerOffer::TYPES as $k => $label)
                            <option value="{{ $k }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <input name="value" type="number" step="0.01" class="input" placeholder="Value (% / ৳ / pts)">
                </div>

                {{-- Scope: whole order / categories / products --}}
                <select name="applies_to" x-model="applies" class="input">
                    @foreach(\App\Models\CustomerOffer::SCOPES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                </select>
                <div x-show="applies==='categories'" x-cloak>
                    <input x-model="catQ" placeholder="Filter categories…" class="input py-1.5 mb-1 text-sm">
                    <div class="max-h-32 overflow-y-auto rounded-lg border border-ink-100 p-2 space-y-1">
                        @foreach($allCategories as $cat)
                            <label class="flex items-center gap-2 text-sm" x-show="catQ==='' || '{{ Str::lower($cat->name) }}'.includes(catQ.toLowerCase())">
                                <input type="checkbox" name="category_ids[]" value="{{ $cat->id }}"> {{ $cat->name }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <div x-show="applies==='products'" x-cloak>
                    <input x-model="prodQ" placeholder="Filter products…" class="input py-1.5 mb-1 text-sm">
                    <div class="max-h-32 overflow-y-auto rounded-lg border border-ink-100 p-2 space-y-1">
                        @foreach($allProducts as $p)
                            <label class="flex items-center gap-2 text-sm" x-show="prodQ==='' || '{{ Str::lower(addslashes($p->name)) }}'.includes(prodQ.toLowerCase())">
                                <input type="checkbox" name="product_ids[]" value="{{ $p->id }}"> {{ $p->name }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <input name="code" class="input" placeholder="Code (optional)">
                    <input name="expires_at" type="date" class="input">
                </div>
                <input name="min_subtotal" type="number" step="0.01" class="input" placeholder="Min. cart value ৳ (optional)">
                <textarea name="message" rows="2" class="input" placeholder="Message to the customer (shown on their dashboard &amp; product pages)"></textarea>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="send_sms" value="1"> Also send this message by SMS</label>
                <button class="btn-primary w-full">Add offer</button>
            </form>
        </div>

        {{-- Send SMS --}}
        <div class="card p-5">
            <h2 class="font-semibold mb-3">Send SMS</h2>
            <form action="{{ route('admin.customers.sms', $customer) }}" method="POST" class="space-y-3">
                @csrf
                <textarea name="message" rows="3" class="input" placeholder="Type a message to {{ $customer->phone }}…" maxlength="500" required></textarea>
                <button class="btn-outline w-full" {{ $customer->phone ? '' : 'disabled' }}>Send SMS</button>
            </form>
        </div>
    </div>
</div>
@endsection
