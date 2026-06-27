@extends('layouts.shop')
@section('title', 'My Account')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8 md:py-10">
    <div class="grid md:grid-cols-[220px_1fr] gap-8">
        {{-- Sidebar (desktop) --}}
        <aside class="hidden md:block">
            <div class="card p-3 sticky top-20">@include('customer._nav')</div>
        </aside>

        <div class="min-w-0">
            @include('customer._flash')

            <div class="flex items-center justify-between mb-6">
                <div>
                    <p class="text-sm text-ink-700/60">Welcome back,</p>
                    <h1 class="font-display text-2xl md:text-3xl font-semibold">{{ $customer->name }}</h1>
                </div>
            </div>

            {{-- Summary --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-8">
                <a href="{{ route('account.orders') }}" class="card p-4 hover:border-gold-300 transition">
                    <div class="text-2xl font-semibold text-gold-700">{{ $customer->total_orders }}</div>
                    <div class="text-xs text-ink-700/60">Orders</div>
                </a>
                <div class="card p-4">
                    <div class="text-2xl font-semibold text-gold-700">{{ money($customer->total_spent) }}</div>
                    <div class="text-xs text-ink-700/60">Total spent</div>
                </div>
                <a href="{{ route('account.loved') }}" class="card p-4 hover:border-gold-300 transition">
                    <div class="text-2xl font-semibold text-red-500">{{ $lovedCount }}</div>
                    <div class="text-xs text-ink-700/60">Loved items</div>
                </a>
                <a href="{{ route('account.reviews') }}" class="card p-4 hover:border-gold-300 transition">
                    <div class="text-2xl font-semibold text-gold-700">{{ $reviewCount }}</div>
                    <div class="text-xs text-ink-700/60">Reviews</div>
                </a>
            </div>

            {{-- Rewards & offers (collapsible) --}}
            @if($loyaltyEnabled)
                @php
                    $msDone = collect($milestones)->where('done', true)->count();
                    $msTotal = count($milestones);
                    $L = app(\App\Services\LoyaltyService::class);
                    $storeName = \App\Models\Setting::get('store_name', config('store.name'));
                    $per1000 = (int) round($L->earnPerTaka() * 1000);
                    $value100 = money($L->pointsValue(100));
                @endphp
                <div class="card mb-8 overflow-hidden" x-data="{
                        open: true, shareMsg: '', points: {{ $points }},
                        url: '{{ route('home') }}',
                        async share(platform) {
                            const u = encodeURIComponent(this.url);
                            const t = encodeURIComponent('Shop handcrafted jewelry at {{ $storeName }}');
                            if (platform === 'facebook') window.open('https://www.facebook.com/sharer/sharer.php?u=' + u, '_blank', 'width=600,height=500');
                            else if (platform === 'messenger') window.location.href = 'fb-messenger://share/?link=' + u;
                            else if (platform === 'whatsapp') window.open('https://wa.me/?text=' + t + '%20' + u, '_blank');
                            else if (platform === 'copy') { try { await navigator.clipboard.writeText(this.url); } catch (e) {} }
                            try {
                                const res = await fetch('{{ route('account.share') }}', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                                    body: JSON.stringify({ platform })
                                });
                                const data = await res.json();
                                this.shareMsg = data.message || '';
                                if (data.ok && data.points !== undefined) this.points = data.points;
                            } catch (e) {}
                        }
                    }">
                    <button type="button" @click="open = !open" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="flex items-center gap-3">
                            <span class="text-2xl">🎁</span>
                            <span>
                                <span class="block font-semibold">Rewards &amp; offers</span>
                                <span class="block text-xs text-ink-700/60"><span x-text="points"></span> points · {{ money($L->pointsValue($points)) }} value</span>
                            </span>
                        </span>
                        <svg class="w-5 h-5 text-ink-700/50 transition" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>

                    <div x-show="open" x-collapse>
                        <div class="px-5 pb-5 space-y-5">
                            {{-- Points summary --}}
                            <div class="rounded-xl bg-gold-50 border border-gold-200 p-4 flex items-center justify-between flex-wrap gap-3">
                                <div>
                                    <div class="text-3xl font-semibold text-gold-700"><span x-text="points"></span> <span class="text-base font-normal">points</span></div>
                                    <div class="text-xs text-ink-700/60">Worth <strong>{{ money($L->pointsValue($points)) }}</strong> — 100 points = {{ $value100 }} to redeem at checkout</div>
                                </div>
                                <a href="{{ route('shop') }}" class="btn-primary text-sm py-2">Shop &amp; earn more</a>
                            </div>

                            {{-- Membership tier --}}
                            <div class="rounded-xl border border-ink-100 p-4">
                                <div class="flex items-center justify-between">
                                    <span class="inline-flex items-center gap-2 font-semibold">
                                        <span class="text-lg">{{ ['silver'=>'🥈','gold'=>'🥇','platinum'=>'💎'][$tier['current']['key']] ?? '⭐' }}</span>
                                        {{ $tier['current']['label'] }} member
                                    </span>
                                    <span class="text-xs text-ink-700/50">{{ number_format($tier['lifetime']) }} lifetime pts</span>
                                </div>
                                <p class="text-xs text-ink-700/60 mt-1">{{ $tier['current']['perk'] }}</p>
                                @if($tier['next'])
                                    <div class="mt-3">
                                        <div class="h-2 rounded-full bg-ink-100 overflow-hidden"><div class="h-full bg-gold-600" style="width: {{ $tier['progress'] }}%"></div></div>
                                        <p class="text-xs text-ink-700/50 mt-1">Earn {{ number_format($tier['to_next']) }} more points to reach <strong>{{ $tier['next']['label'] }}</strong> ({{ $tier['next']['perk'] }}).</p>
                                    </div>
                                @endif
                            </div>

                            {{-- How to earn (encourages bigger orders) --}}
                            <div class="rounded-lg bg-ink-50 border border-ink-100 p-3 text-xs text-ink-700/80 space-y-1">
                                <p>🛍️ Earn <strong>{{ $per1000 }} points</strong> for every <strong>৳1000</strong> you spend — added once your order is <strong>delivered</strong>. The more you buy, the more you save!</p>
                                <p>⭐ Write a review: <strong>+{{ $L->reviewPoints() }} points</strong> (+{{ $L->reviewPhotoBonus() }} with a photo) · 📣 Share: <strong>+{{ $L->sharePoints() }} points</strong>/week</p>
                                <p>💰 <strong>100 points = {{ $value100 }}</strong> — use them to cut your bill at checkout.</p>
                            </div>

                            {{-- Refer a friend --}}
                            <div class="rounded-xl bg-gold-600 text-white p-4" x-data="{ copied: false, link: '{{ route('customer.register', ['ref' => $referralCode]) }}' }">
                                <h3 class="font-semibold flex items-center gap-2">🤝 Refer friends, both earn {{ $referralPoints }} points</h3>
                                <p class="text-xs text-white/80 mt-1">Share your link. When a friend signs up and their first order is delivered, you <strong>both</strong> get {{ $referralPoints }} points.@if($referralCount > 0) You've referred <strong>{{ $referralCount }}</strong> so far!@endif</p>
                                <div class="mt-3 flex items-center gap-2">
                                    <input readonly :value="link" class="flex-1 min-w-0 rounded-md px-2 py-1.5 text-xs text-ink-900" @focus="$event.target.select()">
                                    <button type="button" @click="navigator.clipboard.writeText(link); copied = true; setTimeout(() => copied = false, 1500)" class="shrink-0 rounded-md bg-white/15 hover:bg-white/25 px-3 py-1.5 text-xs font-medium" x-text="copied ? 'Copied!' : 'Copy'"></button>
                                    <a :href="'https://wa.me/?text=' + encodeURIComponent('Shop at {{ $storeName }} — sign up with my link and we both get rewards! ' + link)" target="_blank" class="shrink-0 rounded-md bg-white/15 hover:bg-white/25 px-3 py-1.5 text-xs font-medium">WhatsApp</a>
                                </div>
                            </div>

                            {{-- Personalised offers --}}
                            @if($liveOffers->isNotEmpty())
                                <div>
                                    <h3 class="text-sm font-semibold mb-2">Your exclusive offers</h3>
                                    <div class="space-y-2">
                                        @foreach($liveOffers as $offer)
                                            <div class="rounded-lg border border-gold-200 bg-white p-3 flex items-center justify-between gap-3">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-medium">{{ $offer->title }}</p>
                                                    @if($offer->description)<p class="text-xs text-ink-700/60">{{ $offer->description }}</p>@endif
                                                    <p class="text-xs text-gold-700 mt-0.5">{{ $offer->rewardText() }}@if($offer->expires_at) · expires {{ $offer->expires_at->format('d M') }}@endif</p>
                                                </div>
                                                @if($offer->code)
                                                    <span class="shrink-0 font-mono text-xs rounded-full border border-gold-300 px-2.5 py-1 bg-gold-50">{{ $offer->code }}</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Weekly milestones --}}
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="text-sm font-semibold">This week's milestones</h3>
                                    <span class="text-xs text-ink-700/60">{{ $msDone }}/{{ $msTotal }} done</span>
                                </div>
                                <div class="h-2 rounded-full bg-ink-100 overflow-hidden mb-3">
                                    <div class="h-full bg-gold-600 transition-all" style="width: {{ $msTotal ? round($msDone / $msTotal * 100) : 0 }}%"></div>
                                </div>
                                <ul class="space-y-2">
                                    @foreach($milestones as $m)
                                        <li class="flex items-center gap-3 text-sm">
                                            <span class="w-7 h-7 grid place-items-center rounded-full {{ $m['done'] ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-400' }}">
                                                {!! $m['done'] ? '&check;' : $m['icon'] !!}
                                            </span>
                                            <span class="flex-1 {{ $m['done'] ? 'text-ink-700/50 line-through' : '' }}">{{ $m['label'] }}</span>
                                            <span class="text-xs font-medium text-gold-700">+{{ $m['points'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>

                                {{-- Share to earn --}}
                                <div class="mt-4 rounded-lg bg-ink-50 p-3">
                                    <p class="text-xs text-ink-700/70 mb-2">Share {{ $storeName }} to friends to earn <strong>+{{ $L->sharePoints() }} points</strong> (once a week):</p>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <button type="button" @click="share('messenger')" class="btn-outline text-xs py-1.5 px-3 inline-flex items-center gap-1.5">
                                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.27 2 2 6.2 2 11.7c0 2.88 1.18 5.37 3.1 7.1.16.14.26.34.27.56l.05 1.75c.02.56.6.92 1.11.7l1.95-.86c.17-.07.36-.09.54-.04 1.25.34 2.58.5 3.88.5 5.73 0 10-4.2 10-9.7C22 6.2 17.73 2 12 2zm6 7.46l-2.93 4.65c-.47.74-1.47.93-2.18.4l-2.33-1.75a.6.6 0 00-.72 0l-3.14 2.39c-.42.32-.97-.18-.69-.63l2.93-4.65c.47-.74 1.47-.93 2.18-.4l2.33 1.75c.21.16.51.16.72 0l3.14-2.38c.42-.32.97.18.69.62z"/></svg>
                                            Messenger
                                        </button>
                                        <button type="button" @click="share('whatsapp')" class="btn-outline text-xs py-1.5 px-3">WhatsApp</button>
                                        <button type="button" @click="share('facebook')" class="btn-outline text-xs py-1.5 px-3">Facebook</button>
                                        <button type="button" @click="share('copy')" class="btn-outline text-xs py-1.5 px-3">Copy link</button>
                                        <span class="text-xs text-green-700" x-text="shareMsg"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            @endif

            {{-- Default address --}}
            <div class="card p-5 mb-8">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="font-semibold">Default shipping address</h2>
                    <a href="{{ route('account.addresses') }}" class="text-sm text-gold-700 hover:underline">Manage →</a>
                </div>
                @if($defaultAddress)
                    <p class="text-sm text-ink-800">{{ $defaultAddress->name }} · {{ $defaultAddress->phone }}</p>
                    <p class="text-sm text-ink-700/70">{{ collect([$defaultAddress->address, $defaultAddress->area, $defaultAddress->district])->filter()->implode(', ') }}</p>
                @else
                    <p class="text-sm text-ink-700/60">No saved address yet. <a href="{{ route('account.addresses') }}" class="text-gold-700 hover:underline">Add one</a> for faster checkout.</p>
                @endif
            </div>

            {{-- Recent orders --}}
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-display text-xl font-semibold">Recent orders</h2>
                <a href="{{ route('account.orders') }}" class="text-sm text-gold-700 hover:underline">View all →</a>
            </div>
            @forelse($orders as $order)
                <div class="card p-4 mb-3 flex flex-wrap items-center gap-3">
                    <div class="flex-1 min-w-0">
                        <a href="{{ route('account.order', $order->order_number) }}" class="font-medium text-gold-700 hover:underline">#{{ $order->order_number }}</a>
                        <span class="text-xs text-ink-700/50 ml-2">{{ $order->created_at->format('d M Y') }}</span>
                        <div class="text-sm text-ink-700/70">{{ money($order->total) }} · <span class="badge bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span></div>
                    </div>
                    <form action="{{ route('account.reorder', $order->order_number) }}" method="POST">@csrf
                        <button class="btn-outline text-sm py-1.5">Reorder</button>
                    </form>
                </div>
            @empty
                <div class="card p-6 text-center text-sm text-ink-700/60">
                    No orders yet. <a href="{{ route('shop') }}" class="text-gold-700 hover:underline">Start shopping →</a>
                </div>
            @endforelse
        </div>
    </div>
</div>
@endsection
