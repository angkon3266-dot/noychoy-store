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

            {{-- Member savings --}}
            @if(($memberPercent ?? 0) > 0 || ($memberSaved ?? 0) > 0)
                <div class="mb-6 rounded-xl border-2 border-gold-300 bg-gradient-to-r from-gold-100/70 to-white p-4 flex items-center gap-4">
                    <span class="text-3xl">🎖️</span>
                    <div>
                        <p class="font-semibold text-gold-800">You're a member — {{ rtrim(rtrim(number_format($memberPercent ?? 0,1),'0'),'.') }}% off on every order.</p>
                        <p class="text-sm text-ink-700/70">You've saved <strong class="text-gold-700">{{ money($memberSaved ?? 0) }}</strong> so far as a member. Keep shopping to save more!</p>
                    </div>
                </div>
            @endif

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

            {{-- Exclusive offers (always shown, independent of the loyalty program) --}}
            @if($liveOffers->isNotEmpty())
                <div class="card p-5 mb-8 border-gold-300">
                    <h2 class="font-semibold mb-3 flex items-center gap-2">🎁 Your exclusive offers <span class="badge bg-gold-600 text-white text-[10px]">{{ $liveOffers->count() }}</span></h2>
                    <div class="grid sm:grid-cols-2 gap-3">
                        @foreach($liveOffers as $offer)
                            <div class="rounded-xl border-2 border-gold-300 bg-gradient-to-r from-gold-100/70 to-white p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold">{{ $offer->title }}</p>
                                        <span class="inline-block badge bg-gold-600 text-white text-[10px] mt-0.5">{{ $offer->rewardText() }}</span>
                                        @if($offer->applies_to !== 'all')<span class="inline-block text-[10px] text-ink-700/60 ml-1">· {{ $offer->scopeLabel() }}</span>@endif
                                        @if($offer->message)<p class="text-xs text-ink-700/70 italic mt-1">{{ $offer->message }}</p>@endif
                                        <p class="text-xs text-green-700 mt-1">✓ Applied automatically at checkout{{ $offer->expires_at ? ' · until '.$offer->expires_at->format('d M') : '' }}</p>
                                    </div>
                                    @if($offer->code)
                                        <span class="shrink-0 font-mono text-xs rounded-full border border-gold-300 px-2.5 py-1 bg-gold-50">{{ $offer->code }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <a href="{{ route('shop') }}" class="btn-primary text-sm mt-4 inline-block">Shop now</a>
                </div>
            @endif

            {{-- Rewards & offers (collapsible) --}}
            @if($loyaltyEnabled)
                @php
                    $msDone = collect($milestones)->where('done', true)->count();
                    $msTotal = count($milestones);
                    $L = app(\App\Services\LoyaltyService::class);
                    $storeName = store_name();
                    $per1000 = (int) round($L->earnPerTaka() * 1000);
                    $value100 = money($L->pointsValue(100));
                @endphp
                <div class="card mb-8 overflow-hidden" x-data="{ open: true, points: {{ $points }} }">
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
                                <p>⭐ Write a review: <strong>+{{ $L->reviewPoints() }} points</strong> (+{{ $L->reviewPhotoBonus() }} with a photo)</p>
                                <p>💰 <strong>100 points = {{ $value100 }}</strong> — use them to cut your bill at checkout.</p>
                            </div>

                            {{-- Member discount status --}}
                            @if($memberUsage && $memberUsage['percent'] > 0)
                                <div class="rounded-xl border border-gold-200 bg-gold-50 p-4">
                                    <h3 class="font-semibold text-sm flex items-center gap-2">💎 Your member discount ({{ rtrim(rtrim(number_format($memberUsage['percent'], 2), '0'), '.') }}% off)</h3>
                                    @if($memberUsage['capped'])
                                        @if($memberUsage['remaining'] > 0)
                                            <p class="text-xs text-ink-700/70 mt-1"><strong>{{ $memberUsage['remaining'] }} of {{ $memberUsage['max'] }}</strong> uses left this period.@if($memberUsage['resets_at']) Resets {{ $memberUsage['resets_at']->format('d M') }}.@endif</p>
                                        @else
                                            <p class="text-xs text-ink-700/70 mt-1">You've used all {{ $memberUsage['max'] }} for now.@if($memberUsage['resets_at']) More unlock {{ $memberUsage['resets_at']->format('d M') }}.@endif</p>
                                        @endif
                                    @else
                                        <p class="text-xs text-ink-700/70 mt-1">Applied automatically on every order.</p>
                                    @endif
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
