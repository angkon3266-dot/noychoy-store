@extends('layouts.admin')
@section('title', 'New order')
@section('heading', 'New order')

@section('content')
{{-- An order taken over the phone, on Messenger or on WhatsApp.

     This exists so the owner never has to check out through the public
     storefront on the customer's behalf — doing that fired the Pixel from her
     own browser and attributed the sale to her session, quietly corrupting the
     ad data on every manual order. --}}

@if($cart)
    {{-- Converting a chased lead. The form is the review step on purpose: the
         basket is a snapshot of what the customer saw, and by the time she
         rings, a piece can be unpublished, a size deactivated or the last one
         sold. Anything that could not be carried over is said here rather than
         failing on save. --}}
    <div class="mb-5 rounded-xl border-2 border-gold-300 bg-gold-50 px-4 py-3">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 class="font-semibold text-sm">
                Converting {{ $cart->name ?: $cart->phone ?: 'a saved basket' }}&rsquo;s abandoned basket
            </h2>
            <a href="{{ route('admin.abandoned.show', $cart) }}" class="text-xs text-gold-700 hover:underline">
                Back to the lead
            </a>
        </div>
        <p class="mt-1 text-xs text-ink-700/70">
            {{ money($cart->subtotal) }} · {{ $cart->item_count }} item{{ $cart->item_count == 1 ? '' : 's' }}
            · saved {{ $cart->updated_at?->diffForHumans() }}.
            Check it over, then create the order — the lead is marked recovered and linked to it.
        </p>
        @if(!empty($prefill['notices']))
            <ul class="mt-2 space-y-1 text-xs text-warning-800 list-disc list-inside">
                @foreach($prefill['notices'] as $notice)
                    <li>{{ $notice }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif

@if(!empty($reminder))
    {{-- "Create order" on a call reminder (owner, 2026-09-17). The same review
         step as converting a lead: the items were noted days ago, so anything
         that can no longer be sold as noted is said here, not thrown on save.
         Saving marks the reminder done and links it to the order. --}}
    <div class="mb-5 rounded-xl border-2 border-gold-300 bg-gold-50 px-4 py-3">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 class="font-semibold text-sm">
                Order from the call reminder for {{ $reminder->displayName() ?: $reminder->phone }}
            </h2>
            <a href="{{ route('admin.reminders.index', ['tab' => $reminder->tab()]) }}" class="text-xs text-gold-700 hover:underline">
                Back to call reminders
            </a>
        </div>
        <p class="mt-1 text-xs text-ink-700/70">
            Due {{ $reminder->dueExact() }}. Check it over, then create the order — the reminder is marked done and linked to it.
        </p>
        @if($reminder->notes)
            <p class="mt-1 text-xs text-ink-700/70 whitespace-pre-line">Notes: {{ \Illuminate\Support\Str::limit($reminder->notes, 300) }}</p>
        @endif
        @if(!empty($prefill['notices']))
            <ul class="mt-2 space-y-1 text-xs text-warning-800 list-disc list-inside">
                @foreach($prefill['notices'] as $notice)
                    <li>{{ $notice }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif

@if($errors->any())
    {{-- Validation used to fail silently here: the form came back with nothing
         said. A refused coupon is now a routine reason to bounce, so the reason
         is at the top, where the page scrolls to after a save. --}}
    <div class="mb-5 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">
        <p class="font-medium">The order was not saved:</p>
        <ul class="mt-1 list-disc list-inside">
            @foreach($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

@php
    // Who the order is for, as far as the form knows: the customer it was
    // opened for, or — after a bounced save — whoever was picked when saving.
    $picked = $restored !== null ? ($restored['picked'] ?? null) : ($prefill['picked'] ?? null);
@endphp

<form method="POST" action="{{ route('admin.orders.store-manual') }}"
      x-data="manualOrder({{ Js::from($products) }}, {{ $shipInside }}, {{ $shipOutside }}, {{ Js::from($prefill) }}, {{ Js::from([
          'restored' => $restored,
          'picked' => $picked,
          'couponLookupUrl' => route('admin.orders.coupon-lookup'),
          'customerSearchUrl' => route('admin.orders.customer-search'),
      ]) }})"
      class="grid lg:grid-cols-3 gap-6">
    @csrf
    @if($cart)<input type="hidden" name="abandoned_cart_id" value="{{ $cart->id }}">@endif
    @if(!empty($reminder))
        <input type="hidden" name="reminder_id" value="{{ $reminder->id }}">
        {{-- A reminder made from a lead closes that lead too, as "Convert to
             order" would — while the lead is still open and on this number. --}}
        @if($reminder->abandonedCart && ! $reminder->abandonedCart->recovered && $reminder->abandonedCart->phone === $reminder->phone)
            <input type="hidden" name="abandoned_cart_id" value="{{ $reminder->abandoned_cart_id }}">
        @endif
    @endif
    {{-- Not saved on the order — the order is still matched to a customer by
         phone. It only lets a bounced save say again whose details these are. --}}
    <input type="hidden" name="picked_customer" value="{{ $picked['id'] ?? '' }}" :value="picked ? picked.id : ''">

    <div class="lg:col-span-2 space-y-6">
        {{-- A blacklisted customer, opened from the list or picked below (owner,
             2026-09-17). They still get an order form — she may have a good
             reason, such as money taken up front — but the flag lives on the
             customer page, nowhere near here, so it is repeated before a parcel
             goes out on trust. --}}
        <div x-show="picked && picked.blacklisted" @if(empty($picked['blacklisted'])) style="display: none" @endif
             class="rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 text-red-800" role="alert">
            <h2 class="font-semibold text-sm" x-text="picked ? (picked.name || picked.phone) + ' is blacklisted' : ''">@if(!empty($picked['blacklisted'])){{ ($picked['name'] ?: $picked['phone']).' is blacklisted' }}@endif</h2>
            <p class="mt-1 text-xs">
                They are flagged as high-risk for cash on delivery. Be sure of this order with them before you save it.
            </p>
        </div>

        <div class="card p-6">
            <h2 class="font-semibold mb-4">Customer</h2>

            {{-- Pick someone the shop already knows (owner, 2026-09-17: "when
                 creating a new order, I need to be able to select existing
                 customer data from name"). Picking fills the boxes below, which
                 stay editable; typing into them by hand for a new customer
                 works exactly as before. The box has no name, so it is never
                 posted with the order. --}}
            <div class="relative mb-4" @click.outside="searchOpen = false">
                <div class="flex items-baseline justify-between gap-2">
                    <label class="label" for="customer-search">Find an existing customer — name or phone</label>
                    <button type="button" x-show="picked" x-cloak @click="clearCustomer()" aria-label="Clear the picked customer" class="text-xs text-gold-700 hover:underline">Clear</button>
                </div>
                <input id="customer-search" type="text" x-model="search" autocomplete="off" enterkeyhint="search"
                       placeholder="Start typing a name or number" class="input"
                       role="combobox" aria-autocomplete="list" aria-controls="customer-results"
                       :aria-expanded="searchOpen ? 'true' : 'false'"
                       :aria-activedescendant="searchOpen && results[highlighted] ? 'customer-option-' + results[highlighted].id : ''"
                       @input.debounce.250ms="searchCustomers()"
                       @focus="searchOpen = results.length > 0"
                       @keydown.arrow-down.prevent="moveHighlight(1)"
                       @keydown.arrow-up.prevent="moveHighlight(-1)"
                       @keydown.enter.prevent="pickHighlighted()"
                       @keydown.escape="searchOpen = false">
                <ul id="customer-results" role="listbox" x-show="searchOpen" x-cloak
                    class="absolute z-20 mt-1 w-full max-h-72 overflow-y-auto rounded-md border border-ink-100 bg-white shadow-lg">
                    <template x-for="(c, i) in results" :key="c.id">
                        <li role="option" :id="'customer-option-' + c.id" :aria-selected="i === highlighted ? 'true' : 'false'"
                            @mousedown.prevent="pickCustomer(c)" @mouseenter="highlighted = i"
                            class="cursor-pointer px-3 py-2" :class="i === highlighted ? 'bg-gold-50' : ''">
                            <div class="flex items-center gap-2 text-sm">
                                <span class="font-medium" x-text="c.name || c.phone"></span>
                                <span x-show="c.blacklisted" class="badge bg-red-100 text-red-700 text-[10px]">Blacklisted</span>
                            </div>
                            <div class="text-xs text-ink-700/60" x-text="c.phone + ' · ' + c.total_orders + (c.total_orders === 1 ? ' order' : ' orders')"></div>
                        </li>
                    </template>
                    <li x-show="searched && !results.length" class="px-3 py-2 text-xs text-ink-700/60">
                        No customer matches — fill in the details below for a new one.
                    </li>
                </ul>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                @php $pf = $prefill['customer'] ?? []; @endphp
                <div><label class="label">Name *</label><input name="name" value="{{ old('name', $pf['name'] ?? '') }}" class="input" required maxlength="120" x-ref="name"></div>
                {{-- The number is who the order is for, so it is also where a
                     coupon given to that number is looked up: on load when it
                     is already filled in, when a customer is picked, and
                     whenever it is changed. --}}
                <div><label class="label">Phone *</label><input name="phone" value="{{ old('phone', $pf['phone'] ?? '') }}" class="input" required placeholder="01XXXXXXXXX"
                                                               x-ref="phone" @change="lookupCoupons()" @blur="lookupCoupons()"></div>
                <div class="sm:col-span-2"><label class="label">Email (optional)</label><input type="email" name="email" value="{{ old('email', $pf['email'] ?? '') }}" class="input" maxlength="160" x-ref="email"></div>
                {{-- A known customer's address is a guess from their saved
                     address or last order, and says which. --}}
                <p x-show="picked && picked.source" @if(empty($picked['source'])) style="display: none" @endif
                   class="sm:col-span-2 rounded-md bg-gold-50 px-3 py-2 text-xs text-ink-700/70"
                   x-text="picked ? picked.source : ''">{{ $picked['source'] ?? '' }}</p>
                <div class="sm:col-span-2"><label class="label">Address *</label><textarea name="address" rows="2" class="input" required maxlength="500" x-ref="address">{{ old('address', $pf['address'] ?? '') }}</textarea></div>
                <div><label class="label">Area / Thana</label><input name="area" value="{{ old('area', $pf['area'] ?? '') }}" class="input" maxlength="120" x-ref="area"></div>
                <div><label class="label">District</label><input name="district" value="{{ old('district', $pf['district'] ?? '') }}" class="input" maxlength="120" x-ref="district"></div>
            </div>
            <label class="mt-4 flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_inside_dhaka" value="1" x-model="inside" @checked(old('is_inside_dhaka', $pf['is_inside_dhaka'] ?? false))> Inside Dhaka
            </label>
            <div class="mt-4"><label class="label">Note (optional)</label><textarea name="notes" rows="2" class="input" maxlength="500">{{ old('notes') }}</textarea></div>
        </div>

        <div class="card p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-semibold">Items</h2>
                <button type="button" @click="addLine()" class="text-sm text-gold-700 hover:underline">+ Add product</button>
            </div>

            <template x-for="(line, i) in lines" :key="i">
                <div class="grid grid-cols-12 gap-2 items-end mb-3 pb-3 border-b border-ink-100 last:border-0">
                    <div class="col-span-6">
                        <label class="label text-xs" x-show="i === 0">Product</label>
                        <select :name="`lines[${i}][product_id]`" x-model.number="line.product_id" class="input py-1.5 text-sm" required>
                            <option value="">Choose…</option>
                            <template x-for="p in products" :key="p.id">
                                <option :value="p.id" x-text="p.name + (p.sku ? ' · ' + p.sku : '')"></option>
                            </template>
                        </select>
                        {{-- A converted line keeps the variation the customer
                             actually chose. The picker cannot select one, so it
                             rides along hidden and is shown as a label — losing
                             it would change what she is selling. --}}
                        <template x-if="line.variant_id">
                            <input type="hidden" :name="`lines[${i}][variant_id]`" :value="line.variant_id">
                        </template>
                        <p class="mt-1 text-[11px] text-ink-700/60" x-show="line.variation" x-text="line.variation"></p>
                        <p class="mt-1 text-[11px] text-warning-800" x-show="hasVariants(line) && !line.variant_id">
                            This product has options — set the price by hand, or order it from the storefront so the option is recorded.
                        </p>
                    </div>
                    <div class="col-span-2">
                        <label class="label text-xs" x-show="i === 0">Qty</label>
                        <input type="number" :name="`lines[${i}][qty]`" x-model.number="line.qty" min="1" max="99" class="input py-1.5 text-sm" required>
                    </div>
                    <div class="col-span-3">
                        <label class="label text-xs" x-show="i === 0">Unit price</label>
                        <input type="number" step="0.01" min="0" :name="`lines[${i}][price]`" x-model.number="line.price"
                               :placeholder="defaultPrice(line)" class="input py-1.5 text-sm">
                    </div>
                    <div class="col-span-1 text-right">
                        <button type="button" @click="lines.splice(i, 1)" x-show="lines.length > 1"
                                class="text-red-600 hover:underline text-xs" aria-label="Remove line">✕</button>
                    </div>
                </div>
            </template>

            <p class="text-xs text-ink-700/70">Leave the price blank to use the catalogue price.</p>
        </div>
    </div>

    <div class="card p-6 h-fit space-y-4">
        <h2 class="font-semibold">Totals</h2>

        <div class="flex justify-between text-sm"><span class="text-ink-700/70">Items</span><span x-text="money(subtotal())"></span></div>

        <div>
            <label class="label text-xs">Delivery charge</label>
            <input type="number" step="0.01" min="0" name="shipping_cost" x-model.number="shipping" class="input py-1.5 text-sm">
            <p class="mt-1 text-[11px] text-ink-700/70">Filled from the zone; change it if you agreed something else.</p>
            <p class="mt-1 text-[11px] text-green-700" x-show="couponFreeDelivery()" x-cloak>Free with the coupon — saved as ৳0.</p>
        </div>

        {{-- A coupon beside her own discount, not instead of it (owner,
             2026-09-17). A coupon given to this number fills itself in; any
             other code can be typed or the box cleared. Nothing here is
             trusted: the server checks and prices the code again on save. --}}
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="label text-xs" for="manual-discount">Discount</label>
                <input id="manual-discount" type="number" step="0.01" min="0" name="discount" value="{{ old('discount', 0) }}" x-model.number="discount" class="input py-1.5 text-sm">
            </div>
            <div>
                <div class="flex items-baseline justify-between gap-2">
                    <label class="label text-xs" for="manual-coupon">Coupon</label>
                    <button type="button" x-show="coupon" x-cloak @click="clearCoupon()" aria-label="Clear the coupon" class="text-[11px] text-ink-700/60 hover:underline">Clear</button>
                </div>
                <input id="manual-coupon" name="coupon_code" value="{{ old('coupon_code') }}" x-model.trim="coupon" maxlength="50" autocomplete="off"
                       placeholder="Code" class="input py-1.5 text-sm uppercase">
            </div>
        </div>

        @error('coupon_code')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

        <div x-show="couponOptions.length" x-cloak class="space-y-1.5">
            <template x-if="couponOptions.length === 1 && sameCode(couponOptions[0].code, coupon)">
                <p class="text-[11px] text-green-700">
                    Coupon for this number<span x-show="couponOptions[0].summary" x-text="' · ' + couponOptions[0].summary"></span>
                </p>
            </template>
            <template x-if="!(couponOptions.length === 1 && sameCode(couponOptions[0].code, coupon))">
                <div class="space-y-1.5">
                    <p class="text-[11px] text-ink-700/70"
                       x-text="couponOptions.length === 1 ? 'A coupon is waiting for this number:' : couponOptions.length + ' coupons are waiting for this number — pick one:'"></p>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="c in couponOptions" :key="c.code">
                            <button type="button" @click="useCoupon(c)" :title="c.label || c.summary"
                                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs text-left"
                                    :class="sameCode(c.code, coupon) ? 'border-gold-400 bg-gold-50' : 'border-ink-100 bg-ink-50 hover:bg-gold-50'">
                                <span class="font-medium" x-text="c.code"></span>
                                <span class="text-ink-700/60" x-show="c.summary" x-text="c.summary"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <div x-show="coupon" x-cloak class="flex justify-between gap-3 text-sm">
            <span class="text-ink-700/70">Coupon <span class="font-medium" x-text="coupon.toUpperCase()"></span></span>
            <span class="text-right">
                <span x-show="couponShort()" class="text-xs text-warning-800" x-text="'Needs an order of at least ' + money(knownCoupon()?.min_order)"></span>
                <span x-show="!couponShort() && couponEstimable()" class="text-green-700" x-text="'−' + money(couponDiscount())"></span>
                <span x-show="!couponShort() && !couponEstimable()" class="text-xs text-ink-700/60">worked out when saved</span>
            </span>
        </div>

        <div class="border-t border-ink-100 pt-3">
            <div class="flex justify-between font-semibold">
                <span>Total (COD)</span><span x-text="money(total())"></span>
            </div>
            <p x-show="coupon && !couponShort() && !couponEstimable()" x-cloak class="mt-1 text-[11px] text-ink-700/60">
                Before the coupon's discount — it is checked and worked out when you save.
            </p>
        </div>

        <div>
            <label class="label text-xs">Status</label>
            <select name="status" class="input py-1.5 text-sm">
                @foreach(\App\Models\Order::STATUSES as $key => $label)
                    <option value="{{ $key }}" @selected($key === 'confirmed')>{{ $label }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-[11px] text-ink-700/70">The customer gets the usual confirmation SMS, and stock comes off now.</p>
        </div>

        <button class="btn-primary w-full">Create order</button>
        <a href="{{ $customer && auth()->user()?->canAccess('customers') ? route('admin.customers.show', $customer) : route('admin.orders.index') }}"
           class="block text-center text-xs text-ink-700/70 hover:underline">Cancel</a>
    </div>
</form>

<script>
    function manualOrder(products, shipInside, shipOutside, prefill, options) {
        // A converted lead opens with her basket in it; everything else opens
        // on one blank line, exactly as before. A save that bounced reopens on
        // exactly what was posted — the lines, charge, discount and coupon —
        // since x-model would otherwise write the defaults over old input.
        const restored = options.restored;
        const seed = (rows) => rows.map((l) => ({
            product_id: l.product_id,
            variant_id: l.variant_id || null,
            variation: l.variation || '',
            qty: l.qty,
            price: (l.price === null || l.price === undefined) ? '' : l.price,
        }));
        const inside = restored
            ? !!restored.inside
            : !!(prefill && prefill.customer && prefill.customer.is_inside_dhaka);
        const seeded = (restored && restored.lines && restored.lines.length)
            ? seed(restored.lines)
            : (prefill && prefill.lines && prefill.lines.length)
                ? seed(prefill.lines)
                : [{ product_id: '', qty: 1, price: '' }];

        return {
            products,
            inside,
            shipping: (restored && restored.shipping !== null && restored.shipping !== undefined)
                ? restored.shipping
                : (inside ? shipInside : shipOutside),
            discount: restored ? (Number(restored.discount) || 0) : 0,
            lines: seeded,

            // Coupons waiting for the number in the Phone box (owner, 2026-09-17).
            coupon: restored ? (restored.coupon || '') : '',
            couponOptions: [],
            // The code this form filled in or was picked from its suggestions —
            // cleared again if the number changes to one it is not for.
            couponAuto: '',
            lookedUp: null,
            lookupSeq: 0,
            // After a bounce the box shows what was posted, even if that was
            // deliberately empty; the suggestions are still offered below it.
            holdAutofill: !!restored,

            // The customer picker (owner, 2026-09-17).
            picked: options.picked || null,
            search: '',
            results: [],
            searched: false,
            searchOpen: false,
            highlighted: -1,
            searchSeq: 0,

            searchCustomers() {
                const term = this.search.trim();
                const seq = ++this.searchSeq;

                if (term.length < 2) {
                    this.results = [];
                    this.searched = false;
                    this.searchOpen = false;
                    return;
                }

                fetch(options.customerSearchUrl + '?q=' + encodeURIComponent(term), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                    .then((res) => (res.ok ? res.json() : null))
                    .then((body) => {
                        // Typing has moved on since this was asked.
                        if (seq !== this.searchSeq || !body || !Array.isArray(body.customers)) return;
                        this.results = body.customers;
                        this.highlighted = body.customers.length ? 0 : -1;
                        this.searched = true;
                        this.searchOpen = true;
                    })
                    // A search that fails leaves the boxes to be typed in by hand.
                    .catch(() => {});
            },
            moveHighlight(step) {
                if (!this.results.length) return;
                if (!this.searchOpen) { this.searchOpen = true; return; }
                this.highlighted = (this.highlighted + step + this.results.length) % this.results.length;
            },
            pickHighlighted() {
                const c = this.searchOpen ? this.results[this.highlighted] : null;
                if (c) this.pickCustomer(c);
            },
            pickCustomer(c) {
                // Into the ordinary inputs, which stay editable and keep posting
                // exactly what they did before there was a picker.
                const fill = (ref, value) => {
                    if (this.$refs[ref]) this.$refs[ref].value = (value === null || value === undefined) ? '' : value;
                };
                fill('name', c.name);
                fill('phone', c.phone);
                fill('email', c.email);
                fill('address', c.address);
                fill('area', c.area);
                fill('district', c.district);
                this.inside = !!c.is_inside_dhaka;
                // The charge follows the zone even when the zone did not change,
                // since this is a different person's delivery.
                this.shipping = this.inside ? shipInside : shipOutside;

                this.picked = c;
                this.search = '';
                this.results = [];
                this.searched = false;
                this.searchOpen = false;

                // Setting .value fires no change event, so ask directly.
                this.lookupCoupons();
            },
            clearCustomer() {
                ['name', 'phone', 'email', 'address', 'area', 'district'].forEach((ref) => {
                    if (this.$refs[ref]) this.$refs[ref].value = '';
                });
                this.inside = false;
                this.shipping = shipOutside;
                this.picked = null;
                this.lookupCoupons();
            },

            addLine() { this.lines.push({ product_id: '', qty: 1, price: '' }); },
            find(line) { return this.products.find((p) => p.id === line.product_id) || null; },
            hasVariants(line) { return !!this.find(line)?.has_variants; },
            defaultPrice(line) { const p = this.find(line); return p ? String(p.price) : ''; },

            subtotal() {
                return this.lines.reduce((sum, l) => {
                    const p = this.find(l);
                    const unit = (l.price === '' || l.price === null) ? (p ? Number(p.price) : 0) : Number(l.price);
                    return sum + unit * (Number(l.qty) || 0);
                }, 0);
            },
            total() {
                return Math.max(0, this.subtotal() - (Number(this.discount) || 0) - this.couponDiscount() + this.deliveryCharge());
            },
            money(n) { return '৳' + Number(n || 0).toLocaleString('en-BD', { maximumFractionDigits: 0 }); },

            sameCode(a, b) { return String(a || '').trim().toUpperCase() === String(b || '').trim().toUpperCase(); },
            normalPhone(raw) {
                // bd_phone(), so "+880 1712-345678" asks about 01712345678.
                let d = String(raw || '').replace(/\D/g, '');
                if (d.startsWith('880')) d = d.slice(3);
                if (d.length === 10 && d.startsWith('1')) d = '0' + d;
                return d;
            },

            lookupCoupons() {
                const phone = this.normalPhone(this.$refs.phone ? this.$refs.phone.value : '');
                if (phone === this.lookedUp) return;

                this.lookedUp = phone;
                const seq = ++this.lookupSeq;
                const hold = this.holdAutofill;
                this.holdAutofill = false;

                if (!/^01\d{9}$/.test(phone)) {
                    this.offerCoupons([], seq, hold);
                    return;
                }

                fetch(options.couponLookupUrl + '?phone=' + encodeURIComponent(phone), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                    .then((res) => (res.ok ? res.json() : null))
                    .then((body) => {
                        if (body && Array.isArray(body.coupons)) this.offerCoupons(body.coupons, seq, hold);
                    })
                    // Advisory only: a lookup that fails leaves the form as it was.
                    .catch(() => {});
            },
            offerCoupons(found, seq, hold) {
                // The number has changed since this was asked.
                if (seq !== this.lookupSeq) return;

                // A code filled in for the previous number is not this one's to spend.
                if (this.couponAuto && this.sameCode(this.coupon, this.couponAuto)
                    && !found.some((c) => this.sameCode(c.code, this.coupon))) {
                    this.coupon = '';
                }
                if (!this.sameCode(this.coupon, this.couponAuto)) this.couponAuto = '';

                this.couponOptions = found;

                if (found.length === 1 && this.coupon === '' && !hold) {
                    this.coupon = found[0].code;
                    this.couponAuto = found[0].code;
                }
            },
            useCoupon(c) { this.coupon = c.code; this.couponAuto = c.code; },
            clearCoupon() { this.coupon = ''; this.couponAuto = ''; },

            // The preview. Only a whole-order coupon is estimated here: a scoped
            // one, or one that leaves out sale pieces, depends on categories and
            // sale prices this page does not hold, so it is worked out on save.
            knownCoupon() { return this.couponOptions.find((c) => this.sameCode(c.code, this.coupon)) || null; },
            couponEstimable() { const c = this.knownCoupon(); return !!c && c.applies_to === 'all' && !c.exclude_sale_items; },
            couponShort() {
                const c = this.knownCoupon();
                return !!c && c.min_order !== null && c.min_order !== undefined && this.subtotal() < Number(c.min_order);
            },
            couponDiscount() {
                const c = this.knownCoupon();
                if (!c || !this.couponEstimable() || this.couponShort()) return 0;
                const base = this.subtotal();
                const off = c.type === 'percent' ? base * (Number(c.value) || 0) / 100 : (Number(c.value) || 0);
                // Never past the items, and never so far that her own discount
                // and the coupon together pass them — the rule the save uses.
                return Math.max(0, Math.min(off, base, base - (Number(this.discount) || 0)));
            },
            couponFreeDelivery() { const c = this.knownCoupon(); return !!c && !!c.free_shipping && !this.couponShort(); },
            deliveryCharge() { return this.couponFreeDelivery() ? 0 : (Number(this.shipping) || 0); },

            init() {
                // Keep the delivery charge in step with the zone unless it has
                // been typed over.
                this.$watch('inside', (v) => { this.shipping = v ? shipInside : shipOutside; });

                // A seeded line's <select> is bound by x-model before the x-for
                // inside it has rendered any <option>, so the browser has
                // nothing to select and the row silently posts an empty
                // product. Re-assert the value once the options exist.
                this.$nextTick(() => {
                    this.$el.querySelectorAll('select[name$="[product_id]"]').forEach((el) => {
                        const at = (el.getAttribute('name') || '').match(/lines\[(\d+)\]/);
                        const line = at ? this.lines[Number(at[1])] : null;
                        if (line && line.product_id) {
                            el.value = line.product_id;
                        }
                    });

                    // A number already in the box — a customer's, a lead's, or
                    // the one a bounced save posted — is asked about straight away.
                    // Picking a customer later asks again from pickCustomer().
                    // After the tick, because x-ref is not registered until the
                    // Phone input itself has initialised.
                    this.lookupCoupons();
                });
            },
        };
    }
</script>
@endsection
