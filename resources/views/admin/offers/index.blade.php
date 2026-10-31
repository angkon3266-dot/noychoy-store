@extends('layouts.admin')
@section('title', 'Offers')
@section('heading', 'Offers & promotions')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif
@if(session('error'))<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2.5 text-sm">{{ session('error') }}</div>@endif

<p class="text-sm text-ink-700/70 mb-4 max-w-3xl">
    Automatic promotions that apply at checkout <strong>and</strong> show on product pages to boost conversions —
    e.g. “Free delivery over ৳2000”, “Buy any 2, get 5% off”, “Members get an extra 3% off”.
    For single-use codes use <a href="{{ route('admin.coupons.index') }}" class="text-gold-700 underline">Coupons</a>.
</p>

@php
    // The settings cards start folded. The one whose form was just turned
    // down (its old input carries `_card`) or just saved (the controller
    // flashes `offers_card`) opens, with that form's error inside it — the
    // error used to land in the New offer form whichever form it came from.
    $openCard = old('_card') ?: session('offers_card');
    $cardError = fn (string $card) => old('_card') === $card && $errors->any() ? $errors->first() : null;
    $pct = fn ($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
    $onBadge = '<span class="badge bg-green-100 text-green-700">%s</span>';
    $offBadge = '<span class="badge bg-ink-100 text-ink-700">Off</span>';
@endphp

<div class="max-w-3xl space-y-3 mb-6">
{{-- Reward ladder — every paid piece climbs a rung, each rung's reward stays unlocked --}}
<x-admin.settings-card id="ladder" title="Reward ladder" :open="$openCard === 'ladder'">
    <x-slot:status>
        {!! $giftLadder['enabled'] ? sprintf($onBadge, 'Live') : $offBadge !!}
        <span class="text-xs text-ink-700/50">{{ count($giftLadder['tiers']) ? count($giftLadder['tiers']).' '.Str::plural('rung', count($giftLadder['tiers'])) : 'no rungs yet' }}</span>
    </x-slot:status>
    <div x-data="{ tiers: @js($giftLadder['tiers']) }">
    <p class="text-xs text-ink-700/60 mb-3">
        “Add more, save more”: every <strong>paid piece</strong> in the cart climbs one rung, and each rung's reward
        <strong>stays unlocked</strong> as the cart grows — at 4 pieces the customer holds rungs 1 to 4 at once.
        Four reward types: <strong>৳ off</strong>, <strong>% off</strong> what is paid, <strong>free delivery</strong>, and a
        <strong>free gift</strong> (the customer adds a piece from the gifts collection and the cheapest one goes to ৳0;
        a gift never counts as a paid piece). The storefront shows the ladder in the cart and mini-cart, and quotes the
        reward before anything is added: on the product page price, in a ladder row under Add to cart, on product cards,
        and in the Frequently bought together prices — always worked out from the rungs saved here and what is already
        in the shopper's cart. Build the gifts collection under
        <a href="{{ route('admin.collections.index') }}" class="text-gold-700 underline">Products → Collections</a> first.
    </p>
    <form action="{{ route('admin.offers.gift-ladder') }}" method="POST">
        @csrf
        <input type="hidden" name="_card" value="ladder">
        @if($err = $cardError('ladder'))<div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mb-3">{{ $err }}</div>@endif
        <div class="flex flex-wrap items-end gap-3 mb-3">
            <label class="flex items-center gap-2 rounded-lg border border-ink-100 px-3 py-2.5 text-sm">
                <input type="checkbox" name="enabled" value="1" @checked($giftLadder['enabled'])>
                Ladder is live
            </label>
            <div class="flex-1 min-w-[220px]">
                <label class="label">Gifts collection (for the free-gift rung)</label>
                <select name="gifts_collection_id" class="input">
                    <option value="">— none —</option>
                    @foreach($giftLadder['collections'] as $c)
                        <option value="{{ $c['id'] }}" @selected($giftLadder['gifts_collection_id'] === $c['id'])>{{ $c['name'] }} ({{ $c['count'] }})</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-ink-700/60">
                        <th class="py-1 pr-2 font-medium">Rung</th>
                        <th class="py-1 pr-2 font-medium">Unlocks at (paid pieces)</th>
                        <th class="py-1 pr-2 font-medium">Reward</th>
                        <th class="py-1 pr-2 font-medium">Value</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(t, i) in tiers" :key="i">
                        <tr>
                            <td class="py-1 pr-2 text-ink-700/60" x-text="i + 1"></td>
                            <td class="py-1 pr-2"><input type="number" min="1" max="50" :name="`tiers[${i}][threshold]`" x-model.number="t.threshold" class="input py-1.5 w-24"></td>
                            <td class="py-1 pr-2">
                                <select :name="`tiers[${i}][type]`" x-model="t.type" class="input py-1.5 w-40">
                                    <option value="flat">৳ off</option>
                                    <option value="percent">% off what is paid</option>
                                    <option value="free_delivery">Free delivery</option>
                                    <option value="free_gift">Free gift</option>
                                </select>
                            </td>
                            <td class="py-1 pr-2">
                                <input type="number" step="0.01" min="0" :name="`tiers[${i}][value]`" x-model="t.value" class="input py-1.5 w-28"
                                       x-show="t.type === 'flat' || t.type === 'percent'" :placeholder="t.type === 'percent' ? '%' : '৳'">
                                <span x-show="t.type !== 'flat' && t.type !== 'percent'" class="text-xs text-ink-700/50">—</span>
                            </td>
                            <td class="py-1"><button type="button" @click="tiers.splice(i, 1)" class="text-red-500 px-1 text-lg leading-none" aria-label="Remove rung">&times;</button></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center gap-3 mt-3">
            <button type="button" x-show="tiers.length < {{ (int) $giftLadder['max_tiers'] }}"
                    @click="tiers.push({ threshold: (tiers.length ? Math.max(...tiers.map(t => +t.threshold || 0)) : 0) + 1, type: 'flat', value: '' })"
                    class="btn-outline text-xs py-1">+ Add rung</button>
            <button class="btn-primary">Save ladder</button>
        </div>
        <p class="text-xs text-ink-700/50 mt-2">
            Rewards are re-checked server-side at checkout, and a free gift's stock is deducted like any other sale.
            A rung at 1 piece applies to <strong>every</strong> order — check your margins before switching it on.
        </p>
    </form>
    </div>
</x-admin.settings-card>

{{-- Spin & win — a wheel shoppers spin while browsing or at checkout; the slice it lands on comes off their order --}}
<x-admin.settings-card id="spin-wheel" title="Spin & win wheel" :open="$openCard === 'spin-wheel'">
    <x-slot:status>
        {!! $spinWheel['live'] ? sprintf($onBadge, 'Live') : $offBadge !!}
        <span class="text-xs text-ink-700/50">{{ count($spinWheel['segments']) }} {{ Str::plural('slice', count($spinWheel['segments'])) }} · {{ $spinWheel['won'] }} won, {{ $spinWheel['used'] }} used on an order</span>
    </x-slot:status>
    <div x-data="{
            segs: @js(old('segments', $spinWheel['segments'])),
            total() { return this.segs.reduce((n, s) => n + (Math.max(0, +s.weight) || 0), 0) },
            chance(s) { const t = this.total(); return t ? Math.round((Math.max(0, +s.weight) || 0) / t * 1000) / 10 : 0 },
         }">
    <p class="text-xs text-ink-700/60 mb-3">
        A casino-style wheel. It pops up while a visitor browses (once per visit), and is also in the menu, a floating
        button, a homepage band (move or hide it in Appearance → Homepage), the cart and the top of the checkout.
        The customer gets <strong>one spin</strong>; whatever slice it lands on is applied to her order automatically as a
        single-use coupon (it shows in her order summary, and in
        <a href="{{ route('admin.coupons.index') }}" class="text-gold-700 underline">Coupons</a> as “Spin wheel · …”).
        A <strong>free gift</strong> slice puts the piece you pick into her bag at ৳0 — with any other piece, never on its own.
        <strong>Chance</strong> is a weight, not a percent — the % column shows what each slice actually comes up as.
        The draw happens on the server, so nobody can pick their slice. A prize stacks with your offers like any coupon;
        a code the customer typed herself stays, and the wheel is hidden for that order.
    </p>
    <form action="{{ route('admin.offers.spin-wheel') }}" method="POST">
        @csrf
        <input type="hidden" name="_card" value="spin-wheel">
        @if($err = $cardError('spin-wheel'))<div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mb-3">{{ $err }}</div>@endif
        <div class="flex flex-wrap items-end gap-3 mb-3">
            <label class="flex items-center gap-2 rounded-lg border border-ink-100 px-3 py-2.5 text-sm">
                <input type="checkbox" name="enabled" value="1" @checked(old('_card') === 'spin-wheel' ? old('enabled') : $spinWheel['enabled'])>
                Wheel is live
            </label>
            <label class="flex items-center gap-2 rounded-lg border border-ink-100 px-3 py-2.5 text-sm">
                <input type="checkbox" name="once_per_phone" value="1" @checked(old('_card') === 'spin-wheel' ? old('once_per_phone') : $spinWheel['once_per_phone'])>
                One prize per phone number
            </label>
            <label class="flex items-center gap-2 rounded-lg border border-ink-100 px-3 py-2.5 text-sm">
                <input type="checkbox" name="popup" value="1" @checked(old('_card') === 'spin-wheel' ? old('popup') : $spinWheel['popup'])>
                Pop up while browsing
            </label>
        </div>
        <div class="grid sm:grid-cols-2 gap-3 mb-3">
            <div>
                <label class="label">Heading</label>
                <input name="title" maxlength="60" value="{{ old('title', $spinWheel['title']) }}" class="input">
            </div>
            <div>
                <label class="label">Line under it</label>
                <input name="subtitle" maxlength="160" value="{{ old('subtitle', $spinWheel['subtitle']) }}" class="input">
            </div>
            <div>
                <label class="label">Prize valid for (hours)</label>
                <input name="valid_hours" type="number" min="1" max="720" value="{{ old('valid_hours', $spinWheel['valid_hours']) }}" class="input" required>
            </div>
            <div>
                <label class="label">Prize needs an order of at least (৳, after offers)</label>
                <input name="min_cart" type="number" min="0" step="1" value="{{ old('min_cart', (int) $spinWheel['min_cart']) }}" class="input" placeholder="0 = any order">
            </div>
            <div>
                <label class="label">Pop up after this many seconds browsing</label>
                <input name="popup_delay" type="number" min="3" max="600" value="{{ old('popup_delay', $spinWheel['popup_delay']) }}" class="input">
            </div>
            <div>
                <label class="label">…or when she opens this many pages</label>
                <input name="popup_pages" type="number" min="1" max="20" value="{{ old('popup_pages', $spinWheel['popup_pages']) }}" class="input">
                <p class="text-[11px] text-ink-700/50 mt-1">Whichever comes first, but never on the first page of a visit: an ad lands on a product page, and she reads it before anything pops up (unless she has already put something in her bag). It opens once per visit; closed, it stays closed until the next visit.</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-ink-700/60">
                        <th class="py-1 pr-2 font-medium">Colour</th>
                        <th class="py-1 pr-2 font-medium">Text on the slice</th>
                        <th class="py-1 pr-2 font-medium">Prize</th>
                        <th class="py-1 pr-2 font-medium">Value</th>
                        <th class="py-1 pr-2 font-medium">Chance</th>
                        <th class="py-1 pr-2 font-medium">%</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(s, i) in segs" :key="i">
                        <tr>
                            <td class="py-1 pr-2"><input type="color" :name="`segments[${i}][color]`" x-model="s.color" class="h-9 w-11 cursor-pointer rounded border border-ink-100 bg-white p-0.5" aria-label="Slice colour"></td>
                            <td class="py-1 pr-2"><input type="text" maxlength="24" :name="`segments[${i}][label]`" x-model="s.label" class="input py-1.5 w-36" placeholder="e.g. 10% OFF"></td>
                            <td class="py-1 pr-2">
                                <select :name="`segments[${i}][type]`" x-model="s.type" class="input py-1.5 w-36">
                                    <option value="percent">% off</option>
                                    <option value="flat">৳ off</option>
                                    <option value="free_delivery">Free delivery</option>
                                    <option value="free_gift">Free gift</option>
                                    <option value="none">No prize</option>
                                </select>
                            </td>
                            <td class="py-1 pr-2">
                                <input type="number" step="0.01" min="0" :name="`segments[${i}][value]`" x-model="s.value" class="input py-1.5 w-24"
                                       x-show="s.type === 'flat' || s.type === 'percent'" :placeholder="s.type === 'percent' ? '%' : '৳'">
                                <template x-if="s.type === 'free_gift'">
                                    <select :name="`segments[${i}][product_id]`" x-model.number="s.product_id" class="input py-1.5 w-56" required aria-label="Gift piece">
                                        <option value="">Pick the gift…</option>
                                        @foreach($spinWheel['gift_products'] as $gp)
                                            <option value="{{ $gp['id'] }}">{{ $gp['name'] }}</option>
                                        @endforeach
                                    </select>
                                </template>
                                <span x-show="s.type !== 'flat' && s.type !== 'percent' && s.type !== 'free_gift'" class="text-xs text-ink-700/50">—</span>
                            </td>
                            <td class="py-1 pr-2"><input type="number" min="0" max="1000" :name="`segments[${i}][weight]`" x-model.number="s.weight" class="input py-1.5 w-20" required></td>
                            <td class="py-1 pr-2 text-xs text-ink-700/70 tabular-nums" x-text="chance(s) + '%'"></td>
                            <td class="py-1"><button type="button" @click="segs.splice(i, 1)" class="text-red-500 px-1 text-lg leading-none" aria-label="Remove slice">&times;</button></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center gap-3 mt-3">
            <button type="button" x-show="segs.length < {{ (int) $spinWheel['max_segments'] }}"
                    @click="segs.push({ label: '', type: 'percent', value: '', weight: 10, color: @js(\App\Support\SpinWheel::PALETTE)[segs.length % {{ count(\App\Support\SpinWheel::PALETTE) }}] })"
                    class="btn-outline text-xs py-1">+ Add slice</button>
            <button class="btn-primary">Save wheel</button>
        </div>
        <p class="text-xs text-ink-700/50 mt-2">
            Between 2 and {{ (int) $spinWheel['max_segments'] }} slices. “No prize” slices (Try again, Better luck next time) count toward the
            wheel but give nothing. A gift must be a piece with no size/colour options; if it sells out, its slice still
            shows but the wheel never stops on it, and its stock comes off like any sale. With “one prize per phone number” on, a number that has already ordered with a wheel prize
            cannot use another — checked when the order is placed.
        </p>
    </form>
    </div>
</x-admin.settings-card>

{{-- Registration offer (shown to guests, applied automatically to logged-in members) --}}
<x-admin.settings-card id="register-offer" title="Register-for-discount offer" :open="$openCard === 'register'">
    <x-slot:status>
        @if((float) $registerOffer['percent'] > 0)
            {!! sprintf($onBadge, 'On') !!}
            <span class="text-xs text-ink-700/50">{{ $pct($registerOffer['percent']) }}% off for members</span>
        @else
            {!! $offBadge !!}
        @endif
    </x-slot:status>
    <p class="text-xs text-ink-700/60 mb-3">Guests see this as a nudge at checkout &amp; on the register page. Logged-in customers get the discount automatically on every order. Set the percent to 0 to turn it off.</p>
    <form action="{{ route('admin.offers.register') }}" method="POST" class="flex flex-wrap items-end gap-3"
          x-data="{ rows: @js($memberOverrides), categories: @js($categories->map(fn($c)=>['id'=>$c->id,'name'=>$c->name])->values()), products: @js($products->map(fn($p)=>['id'=>$p->id,'name'=>$p->name])->values()) }">
        @csrf
        <input type="hidden" name="_card" value="register">
        @if($err = $cardError('register'))<div class="w-full rounded bg-red-50 text-red-700 text-sm px-3 py-2">{{ $err }}</div>@endif
        <div>
            <label class="label">Member discount %</label>
            <input name="register_offer_percent" type="number" step="0.1" min="0" max="90" value="{{ $registerOffer['percent'] }}" class="input w-32">
        </div>
        <div class="flex-1 min-w-[220px]">
            <label class="label">Nudge text</label>
            <input name="register_offer_text" value="{{ $registerOffer['text'] }}" class="input" placeholder="Create an account for an extra discount + points">
        </div>
        <div>
            <label class="label">Max uses</label>
            <input name="register_offer_max_uses" type="number" min="0" value="{{ $registerOffer['max_uses'] }}" class="input w-28" title="0 = unlimited">
        </div>
        <div>
            <label class="label">Per (days)</label>
            <input name="register_offer_window_days" type="number" min="1" value="{{ $registerOffer['window_days'] }}" class="input w-24">
        </div>

        {{-- Per-category / per-product overrides --}}
        <div class="w-full border-t border-ink-100 pt-3 mt-1">
            <h3 class="text-sm font-semibold text-ink-700 mb-1">Category / product overrides (optional)</h3>
            <p class="text-xs text-ink-700/50 mb-2">Members get the % above on everything. Add exceptions here — a different % for a category or a specific product.</p>
            <template x-for="(r, i) in rows" :key="i">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <select x-model="r.type" class="input py-1.5 text-sm w-32"><option value="category">Category</option><option value="product">Product</option></select>
                    <select x-model.number="r.id" class="input py-1.5 text-sm flex-1 min-w-[160px]">
                        <option value="">Choose…</option>
                        <template x-for="opt in (r.type==='product' ? products : categories)" :key="opt.id"><option :value="opt.id" x-text="opt.name"></option></template>
                    </select>
                    <input x-model.number="r.percent" type="number" step="0.1" min="0" max="90" class="input py-1.5 text-sm w-24" placeholder="% off">
                    <button type="button" @click="rows.splice(i,1)" class="text-red-500 px-1 text-lg leading-none">&times;</button>
                </div>
            </template>
            <button type="button" @click="rows.push({ type: 'category', id: '', percent: '' })" class="btn-outline text-xs py-1">+ Add override</button>
            <input type="hidden" name="member_overrides_json" :value="JSON.stringify(rows.filter(r => r.id && r.percent !== ''))">
        </div>

        <button class="btn-primary">Save</button>
        <p class="w-full text-xs text-ink-700/50 mt-1">Logged-in members see this discounted price on every product. The discount applies to at most <strong>Max uses</strong> orders within each rolling window of <strong>Per (days)</strong> days per customer (0 = unlimited).</p>
    </form>
</x-admin.settings-card>

{{-- Loyalty & points configuration --}}
<x-admin.settings-card id="loyalty" title="Loyalty & points" :open="$openCard === 'loyalty'">
    <x-slot:status>
        @if($loyalty['enabled'])
            {!! sprintf($onBadge, 'On') !!}
            <span class="text-xs text-ink-700/50">{{ $loyalty['per_1000'] }} {{ Str::plural('point', (int) $loyalty['per_1000']) }} per ৳1,000</span>
        @else
            {!! $offBadge !!}
        @endif
    </x-slot:status>
    <p class="text-xs text-ink-700/60 mb-3">Control how customers earn and spend points. Points are credited <strong>after an order is delivered</strong>.</p>
    <form action="{{ route('admin.offers.loyalty') }}" method="POST">
        @csrf
        <input type="hidden" name="_card" value="loyalty">
        @if($err = $cardError('loyalty'))<div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mb-3">{{ $err }}</div>@endif
        <label class="flex items-center gap-2 text-sm mb-3"><input type="checkbox" name="enabled" value="1" @checked($loyalty['enabled'])> Enable the points / rewards program</label>
        <div class="grid sm:grid-cols-3 gap-3">
            <div>
                <label class="label">Points per ৳1000 spent</label>
                <input name="per_1000" type="number" step="1" min="0" value="{{ $loyalty['per_1000'] }}" class="input">
            </div>
            <div>
                <label class="label">৳ value of 100 points</label>
                <input name="value_per_100" type="number" step="0.01" min="0" value="{{ $loyalty['value_per_100'] }}" class="input">
            </div>
            <div>
                <label class="label">Points for a review</label>
                <input name="review" type="number" step="1" min="0" value="{{ $loyalty['review'] }}" class="input">
            </div>
            <div>
                <label class="label">Welcome bonus (signup)</label>
                <input name="signup" type="number" step="1" min="0" value="{{ $loyalty['signup'] }}" class="input">
            </div>
            <div>
                <label class="label">Review-with-photo bonus</label>
                <input name="photo_bonus" type="number" step="1" min="0" value="{{ $loyalty['photo_bonus'] }}" class="input">
            </div>
            <div>
                <label class="label">Referral points (each side)</label>
                <input name="referral" type="number" step="1" min="0" value="{{ $loyalty['referral'] }}" class="input" title="Paid to the inviter AND the invited friend when the friend's first order is delivered. 0 switches invites off.">
            </div>
        </div>
        <button class="btn-primary mt-3">Save loyalty settings</button>
    </form>
</x-admin.settings-card>

{{-- Birthday & anniversary automations --}}
<x-admin.settings-card id="occasions" title="Birthday & anniversary messages" :open="$openCard === 'occasions'">
    <x-slot:status>
        @if($occasions['enabled'])
            {!! sprintf($onBadge, 'On') !!}
            <span class="text-xs text-ink-700/50">{{ $occasions['sms'] ? 'in-app + SMS' : 'in-app only' }}{{ (float) $occasions['offer_percent'] > 0 ? ' · '.$pct($occasions['offer_percent']).'% gift on the day' : '' }}</span>
        @else
            {!! $offBadge !!}
        @endif
    </x-slot:status>
    <p class="text-xs text-ink-700/60 mb-3">
        Customers can leave a birthday and an anniversary (day + month) at checkout or in their account —
        <strong>{{ $occasions['on_file'] }}</strong> have so far. A reminder with the matching collection goes out before the date,
        and a wish on the day. In-app + push are free and always sent to members; SMS costs a segment each.
        Runs daily at 09:30.
    </p>
    <form action="{{ route('admin.offers.occasions') }}" method="POST" class="flex flex-wrap items-end gap-3">
        @csrf
        <input type="hidden" name="_card" value="occasions">
        @if($err = $cardError('occasions'))<div class="w-full rounded bg-red-50 text-red-700 text-sm px-3 py-2">{{ $err }}</div>@endif
        <label class="flex items-center gap-2 rounded-lg border border-ink-100 px-3 py-2.5 text-sm">
            <input type="checkbox" name="enabled" value="1" @checked($occasions['enabled'])> Automation on
        </label>
        <label class="flex items-center gap-2 rounded-lg border border-ink-100 px-3 py-2.5 text-sm">
            <input type="checkbox" name="sms" value="1" @checked($occasions['sms'])> Also send SMS
        </label>
        <div>
            <label class="label">Remind (days before)</label>
            <input name="reminder_days" type="number" min="1" max="60" value="{{ $occasions['reminder_days'] }}" class="input w-28">
        </div>
        <div>
            <label class="label">Gift on the day (% off, 0 = none)</label>
            <input name="offer_percent" type="number" step="0.5" min="0" max="90" value="{{ $occasions['offer_percent'] }}" class="input w-32">
        </div>
        <div>
            <label class="label">Gift valid (days)</label>
            <input name="offer_days" type="number" min="1" max="60" value="{{ $occasions['offer_days'] }}" class="input w-24">
        </div>
        <button class="btn-primary">Save</button>
        <p class="w-full text-xs text-ink-700/50 mt-1">
            The gift is a personal one-use offer that applies itself at checkout for logged-in members.
            SMS wording lives under System Config → Integrations → SMS templates (“occasion_reminder”, “occasion_wish”).
        </p>
    </form>
</x-admin.settings-card>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    {{-- Form --}}
    @php
        // Deadline presets, worked out on the server so they are Bangladesh
        // time whatever the clock on the admin's own computer says.
        $offerNow = store_time(now());
        $endPresets = [
            'Tonight' => $offerNow->copy()->setTime(23, 59),
            'Tomorrow night' => $offerNow->copy()->addDay()->setTime(23, 59),
            'In 3 days' => $offerNow->copy()->addDays(3)->setTime(23, 59),
            'In a week' => $offerNow->copy()->addWeek()->setTime(23, 59),
        ];
    @endphp
    <div class="card p-6 h-fit" x-data="{ type: '{{ old('type', $editing->type ?? 'order_percent') }}', applies: '{{ old('applies_to', $editing->applies_to ?? 'all') }}', catQ: '', endsAt: @js(old('ends_at', store_time($editing->ends_at ?? null)?->format('Y-m-d\TH:i') ?? '')) }">
        <h2 class="font-semibold mb-4">{{ $editing ? 'Edit offer' : 'New offer' }}</h2>
        @if($errors->any() && ! old('_card'))<div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mb-3">{{ $errors->first() }}</div>@endif
        <form action="{{ $editing ? route('admin.offers.update', $editing) : route('admin.offers.store') }}" method="POST" class="space-y-3">
            @csrf
            @if($editing) @method('PUT') @endif
            <div><label class="label">Title (shown to customer) *</label><input name="title" value="{{ old('title', $editing->title ?? '') }}" class="input" placeholder="Free delivery over ৳2000" required></div>
            <div><label class="label">Short description</label><input name="description" value="{{ old('description', $editing->description ?? '') }}" class="input" placeholder="On all orders, nationwide"></div>
            <div>
                <label class="label">Type *</label>
                <select name="type" x-model="type" class="input">
                    @foreach($types as $key => $label)<option value="{{ $key }}" @selected(old('type', $editing->type ?? '')==$key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div x-show="type==='order_percent'">
                <label class="label">Discount %</label>
                <input name="percent" type="number" step="0.01" min="0.1" max="90" value="{{ old('percent', $editing->percent ?? '') }}" class="input" placeholder="5">
            </div>

            {{-- Scope --}}
            @php $selOffCats = collect(old('category_ids', $editing->category_ids ?? []))->map(fn($i)=>(int)$i)->all(); @endphp
            @php $selOffProds = collect(old('product_ids', $editing->product_ids ?? []))->map(fn($i)=>(int)$i)->all(); @endphp
            <div>
                <label class="label">Applies to *</label>
                <select name="applies_to" x-model="applies" class="input">
                    @foreach($scopes as $key => $label)<option value="{{ $key }}" @selected(old('applies_to', $editing->applies_to ?? 'all')==$key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div x-show="applies==='categories'" x-cloak>
                <input x-model="catQ" placeholder="Filter categories…" class="input py-1.5 mb-2">
                <div class="max-h-40 overflow-y-auto rounded-lg border border-ink-100 p-2 space-y-1">
                    @foreach($categories as $cat)
                        <label class="flex items-center gap-2 text-sm" x-show="catQ==='' || '{{ Str::lower($cat->name) }}'.includes(catQ.toLowerCase())">
                            <input type="checkbox" name="category_ids[]" value="{{ $cat->id }}" @checked(in_array($cat->id, $selOffCats))> {{ $cat->name }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div x-show="applies==='products'" x-cloak>
                <select name="product_ids[]" multiple size="6" class="input">
                    @foreach($products as $p)<option value="{{ $p->id }}" @selected(in_array($p->id, $selOffProds))>{{ $p->name }}</option>@endforeach
                </select>
                <p class="text-xs text-ink-700/50 mt-1">Ctrl/Cmd-click to select several.</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label">Min. cart value ৳</label><input name="min_subtotal" type="number" step="0.01" value="{{ old('min_subtotal', $editing->min_subtotal ?? '') }}" class="input" placeholder="any"></div>
                <div><label class="label">Min. items</label><input name="min_qty" type="number" min="1" value="{{ old('min_qty', $editing->min_qty ?? '') }}" class="input" placeholder="any"></div>
            </div>
            <div><label class="label">Badge label (optional)</label><input name="badge_label" value="{{ old('badge_label', $editing->badge_label ?? '') }}" class="input" placeholder="FREE SHIPPING"></div>

            <div>
                <label class="label">Deal card picture (optional)</label>
                <x-media-field name="offer_image" :value="theme_asset($editing->image ?? null) ?: ''" folder="offers" />
                <p class="text-xs text-ink-700/50 mt-1">Shown on the “Deals of the Day” card. Leave empty to borrow a photo from whatever this offer applies to — which is why several cards can end up sharing one product's picture. Landscape works best (e.g. 800×500).</p>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="members_only" value="1" @checked(old('members_only', $editing->members_only ?? false))> Members only (logged-in customers) — for “register to save”</label>
            <div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="show_on_pdp" value="1" @checked(old('show_on_pdp', $editing->show_on_pdp ?? true))> Show on product pages</label>
                <p class="text-xs text-ink-700/50 mt-1 ml-6">A percentage discount with no minimum cart value or items also changes the price everywhere: the products it covers show the discounted price with the regular price crossed out, in the shop and in the Meta/Google feeds. Delete or pause the offer and prices go back to normal. Untick to apply it at checkout only.</p>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $editing->is_active ?? true))> Active</label>
            <div>
                <label class="label" for="offer-ends-at">Ends at (optional)</label>
                <div class="flex flex-wrap items-center gap-2">
                    <input id="offer-ends-at" type="datetime-local" name="ends_at" x-model="endsAt" class="input max-w-[16rem]">
                    @foreach($endPresets as $label => $when)
                        <button type="button" class="badge bg-ink-100 text-ink-700 hover:bg-gold-100 hover:text-gold-800" x-on:click="endsAt = @js($when->format('Y-m-d\TH:i'))">{{ $label }}</button>
                    @endforeach
                    <button type="button" class="badge bg-ink-100 text-ink-700 hover:bg-red-100 hover:text-red-700" x-show="endsAt !== ''" x-on:click="endsAt = ''" x-cloak>Clear</button>
                </div>
                <p class="text-xs text-ink-700/50 mt-1">
                    Bangladesh time. Shoppers see a live countdown — on the product, on the cards, in the cart and in your pinned message if it contains <code>{countdown}</code> — and when it reaches zero <strong>the offer stops by itself</strong>: prices go back and checkout stops giving the discount. Orders already placed keep their price. Leave empty to run until you untick Active.
                    @if(($editing?->ends_at) && $editing->hasEnded())
                        <span class="block mt-1 text-red-700">This offer ended {{ store_time($editing->ends_at)->diffForHumans() }} — set a new time (or clear it) to run it again.</span>
                    @endif
                </p>
            </div>
            <div><label class="label">Sort order</label><input name="sort" type="number" value="{{ old('sort', $editing->sort ?? 0) }}" class="input w-24"></div>
            <div class="flex gap-2">
                <button class="btn-primary flex-1">{{ $editing ? 'Save offer' : 'Create offer' }}</button>
                @if($editing)<a href="{{ route('admin.offers.index') }}" class="btn-outline">Cancel</a>@endif
            </div>
        </form>
    </div>

    {{-- List --}}
    <div class="lg:col-span-2 card overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Offer</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Conditions</th><th class="px-4 py-3">Ends</th><th class="px-4 py-3">On PDP</th><th class="px-4 py-3">Active</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($offers as $offer)
                    <tr class="hover:bg-ink-50">
                        <td class="px-4 py-3"><div class="font-medium">{{ $offer->title }}</div><div class="text-xs text-ink-700/50">{{ $offer->description }}</div></td>
                        <td class="px-4 py-3 text-ink-700/70">{{ $types[$offer->type] }}{{ $offer->type==='order_percent' ? ' ('.rtrim(rtrim(number_format($offer->percent,2),'0'),'.').'%)' : '' }}{{ $offer->members_only ? ' · members' : '' }}</td>
                        <td class="px-4 py-3 text-xs text-ink-700/60">
                            {{ $offer->min_subtotal ? '≥ ৳'.number_format($offer->min_subtotal,0) : '' }}
                            {{ $offer->min_qty ? ($offer->min_subtotal ? ' & ' : '').'≥ '.$offer->min_qty.' items' : '' }}
                            {{ ! $offer->min_subtotal && ! $offer->min_qty ? 'always' : '' }}
                        </td>
                        <td class="px-4 py-3 text-xs {{ $offer->hasEnded() ? 'text-red-700' : 'text-ink-700/60' }}">
                            @if($offer->ends_at)
                                {{ store_time($offer->ends_at)->format('d M, g:i A') }}
                                <span class="block">{{ $offer->hasEnded() ? 'ended' : store_time($offer->ends_at)->diffForHumans() }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3">{!! $offer->show_on_pdp ? '✓' : '—' !!}</td>
                        <td class="px-4 py-3">{!! $offer->is_active ? '<span class="badge bg-green-100 text-green-700">Yes</span>' : '<span class="badge bg-ink-100 text-ink-700">No</span>' !!}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.offers.index', ['edit' => $offer->id]) }}" class="text-gold-700 hover:underline">Edit</a>
                            <form action="{{ route('admin.offers.destroy', $offer) }}" method="POST" class="inline" onsubmit="return confirm('Delete this offer?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline ml-2">Delete</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-ink-700/50">No offers yet. Create one on the left.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
