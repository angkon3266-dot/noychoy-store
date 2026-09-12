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

<form method="POST" action="{{ route('admin.orders.store-manual') }}"
      x-data="manualOrder({{ Js::from($products) }}, {{ $shipInside }}, {{ $shipOutside }}, {{ Js::from($prefill) }})"
      class="grid lg:grid-cols-3 gap-6">
    @csrf
    @if($cart)<input type="hidden" name="abandoned_cart_id" value="{{ $cart->id }}">@endif

    <div class="lg:col-span-2 space-y-6">
        <div class="card p-6">
            <h2 class="font-semibold mb-4">Customer</h2>
            <div class="grid sm:grid-cols-2 gap-4">
                @php $pf = $prefill['customer'] ?? []; @endphp
                <div><label class="label">Name *</label><input name="name" value="{{ old('name', $pf['name'] ?? '') }}" class="input" required maxlength="120"></div>
                <div><label class="label">Phone *</label><input name="phone" value="{{ old('phone', $pf['phone'] ?? '') }}" class="input" required placeholder="01XXXXXXXXX"></div>
                <div class="sm:col-span-2"><label class="label">Email (optional)</label><input type="email" name="email" value="{{ old('email', $pf['email'] ?? '') }}" class="input" maxlength="160"></div>
                <div class="sm:col-span-2"><label class="label">Address *</label><textarea name="address" rows="2" class="input" required maxlength="500">{{ old('address', $pf['address'] ?? '') }}</textarea></div>
                <div><label class="label">Area / Thana</label><input name="area" value="{{ old('area', $pf['area'] ?? '') }}" class="input" maxlength="120"></div>
                <div><label class="label">District</label><input name="district" value="{{ old('district') }}" class="input" maxlength="120"></div>
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
        </div>

        <div>
            <label class="label text-xs">Discount</label>
            <input type="number" step="0.01" min="0" name="discount" value="{{ old('discount', 0) }}" x-model.number="discount" class="input py-1.5 text-sm">
        </div>

        <div class="flex justify-between font-semibold border-t border-ink-100 pt-3">
            <span>Total (COD)</span><span x-text="money(total())"></span>
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
        <a href="{{ route('admin.orders.index') }}" class="block text-center text-xs text-ink-700/70 hover:underline">Cancel</a>
    </div>
</form>

<script>
    function manualOrder(products, shipInside, shipOutside, prefill) {
        // A converted lead opens with her basket in it; everything else opens
        // on one blank line, exactly as before.
        const inside = !!(prefill && prefill.customer && prefill.customer.is_inside_dhaka);
        const seeded = (prefill && prefill.lines && prefill.lines.length)
            ? prefill.lines.map((l) => ({
                product_id: l.product_id,
                variant_id: l.variant_id || null,
                variation: l.variation || '',
                qty: l.qty,
                price: (l.price === null || l.price === undefined) ? '' : l.price,
            }))
            : [{ product_id: '', qty: 1, price: '' }];

        return {
            products,
            inside,
            shipping: inside ? shipInside : shipOutside,
            discount: 0,
            lines: seeded,

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
            total() { return Math.max(0, this.subtotal() - (Number(this.discount) || 0) + (Number(this.shipping) || 0)); },
            money(n) { return '৳' + Number(n || 0).toLocaleString('en-BD', { maximumFractionDigits: 0 }); },

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
                });
            },
        };
    }
</script>
@endsection
