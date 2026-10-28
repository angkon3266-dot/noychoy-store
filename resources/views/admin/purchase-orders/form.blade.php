@extends('layouts.admin')
@section('title', $order->exists ? 'Edit purchase order' : 'New purchase order')
@section('heading', $order->exists ? 'Edit PO · '.$order->po_number : 'New purchase order')

@section('content')
@if($errors->any())<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm">{{ $errors->first() }}</div>@endif

<form method="POST" action="{{ $order->exists ? route('admin.purchase-orders.update', $order) : route('admin.purchase-orders.store') }}"
      x-data="poForm({{ Illuminate\Support\Js::from($initialItems) }}, {{ $order->exists ? 'true' : 'false' }})" x-ref="form"
      @input="queueSave()" @change="queueSave()" @submit="onSubmit($event)" class="pb-24">
    @csrf
    <template x-if="exists"><input type="hidden" name="_method" value="PUT"></template>

    <div class="grid lg:grid-cols-3 gap-6">
        {{-- Main: supplier + items --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="card p-5">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h2 class="font-semibold">Order details</h2>
                    <span class="text-xs" :class="saveTone" x-text="saveLabel" role="status" aria-live="polite"></span>
                </div>
                <div class="grid sm:grid-cols-2 gap-3">
                    <div>
                        <label class="label">Supplier *</label>
                        <select name="supplier_id" class="input" required>
                            <option value="">Choose supplier…</option>
                            @foreach($suppliers as $s)
                                <option value="{{ $s->id }}" @selected(old('supplier_id', $order->supplier_id) == $s->id)>{{ $s->name }} ({{ $s->country }})</option>
                            @endforeach
                        </select>
                        @if($suppliers->isEmpty())<p class="text-xs text-red-600 mt-1"><a href="{{ route('admin.suppliers.index') }}" class="underline">Add a supplier</a> first.</p>@endif
                    </div>
                    <div>
                        <label class="label">Status</label>
                        <select name="status" class="input">
                            @foreach($statuses as $key => $label)
                                <option value="{{ $key }}" @selected(old('status', $order->status) == $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">PO number</label>
                        <input name="po_number" x-ref="poNumber" value="{{ old('po_number', $order->po_number) }}" class="input" placeholder="Auto-generated if blank">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label">Ordered</label><input type="date" name="ordered_at" x-ref="orderedAt" value="{{ old('ordered_at', $order->ordered_at?->format('Y-m-d')) }}" class="input"></div>
                        <div><label class="label">Expected</label><input type="date" name="expected_at" value="{{ old('expected_at', $order->expected_at?->format('Y-m-d')) }}" class="input"></div>
                    </div>
                </div>
            </div>

            {{-- Line items --}}
            <div class="card p-4 sm:p-5">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-semibold">Items</h2>
                    <button type="button" @click="addItemAndFocus()" class="btn-outline text-sm py-1.5">+ Add product</button>
                </div>

                <div class="space-y-4">
                    <template x-for="(item, i) in items" :key="i">
                        <div class="rounded-lg border border-ink-100 p-3 overflow-x-auto" data-po-item>
                            <div class="min-w-[560px]">
                                {{-- Top row: thumb + name + sku + delete --}}
                                <div class="flex items-start gap-2">
                                    <span class="w-12 h-12 rounded-md bg-ink-100 overflow-hidden shrink-0 grid place-items-center text-ink-400 text-xs">
                                        <template x-if="item.image_url"><img :src="item.image_url" class="w-full h-full object-cover" alt=""></template>
                                        <template x-if="!item.image_url"><span>IMG</span></template>
                                    </span>
                                    <input :name="`items[${i}][product_name]`" x-model="item.product_name" class="input flex-1" placeholder="Product name *" list="po-products">
                                    <input :name="`items[${i}][sku]`" x-model="item.sku" class="input w-24" placeholder="SKU">
                                    <button type="button" @click="duplicateItem(i)" class="shrink-0 text-ink-700/70 hover:bg-ink-100 rounded p-2" title="Duplicate product">⧉</button>
                                    <button type="button" @click="removeItem(i)" class="shrink-0 text-red-600 hover:bg-red-50 rounded p-2" title="Remove product">🗑</button>
                                </div>

                                {{-- Prices --}}
                                <div class="flex flex-wrap items-end gap-2 mt-2">
                                    <div><label class="label">Unit cost ({{ '' }}<span x-text="currency"></span>)</label><input :name="`items[${i}][unit_cost]`" x-model.number="item.unit_cost" type="number" step="0.01" min="0" class="input w-28"></div>
                                    <div><label class="label">Target</label><input :name="`items[${i}][target_price]`" x-model.number="item.target_price" type="number" step="0.01" min="0" class="input w-24"></div>
                                    <span class="text-sm text-green-700 pb-2" x-show="rate > 0">= ৳<span x-text="(item.unit_cost * rate).toFixed(0)"></span> each</span>
                                    <span class="text-xs text-ink-700/50 pb-2 ml-auto">Qty <strong x-text="itemQty(item)"></strong> · <span x-text="currency"></span> <span x-text="(itemQty(item) * (item.unit_cost||0)).toFixed(2)"></span></span>
                                </div>

                                {{-- Links --}}
                                <div class="flex flex-wrap items-center gap-2 mt-2">
                                    <input :name="`items[${i}][product_link]`" x-model="item.product_link" class="input flex-1 min-w-[180px]" placeholder="Product link (Alibaba…)">
                                    <input :name="`items[${i}][image_url]`" x-model="item.image_url" class="input flex-1 min-w-[180px]" placeholder="Image URL">
                                    <button type="button" @click="fetchImg(item)" class="btn-outline text-xs py-2 whitespace-nowrap">🖼 Auto-fetch</button>
                                </div>

                                {{-- Attributes --}}
                                <div class="flex flex-wrap items-center gap-1.5 mt-3">
                                    <span class="text-xs text-ink-700/50">Attributes:</span>
                                    <template x-for="(a, ai) in item.attribute_names" :key="ai">
                                        <span class="inline-flex items-center gap-1 rounded-full bg-ink-100 px-2 py-0.5 text-xs">
                                            <span x-text="a"></span>
                                            <button type="button" @click="removeAttr(item, a)" class="text-ink-700/50 hover:text-red-600">×</button>
                                        </span>
                                    </template>
                                    <template x-for="preset in attrPresets" :key="preset">
                                        <button type="button" x-show="!item.attribute_names.includes(preset)" @click="addAttr(item, preset)" class="rounded-full border border-dashed border-ink-300 px-2 py-0.5 text-xs text-ink-700/60 hover:border-gold-400">+ <span x-text="preset"></span></button>
                                    </template>
                                    <button type="button" @click="addCustomAttr(item)" class="rounded-full border border-dashed border-ink-300 px-2 py-0.5 text-xs text-ink-700/60 hover:border-gold-400">+ Custom</button>
                                </div>

                                {{-- Variant rows --}}
                                <div class="mt-2">
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-xs text-ink-700/50">Variants · total qty <strong x-text="itemQty(item)"></strong></span>
                                        <button type="button" @click="addVariant(item)" class="text-xs text-gold-700 hover:underline">+ Add row</button>
                                    </div>
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="text-left text-xs text-ink-700/50">
                                                <template x-for="a in item.attribute_names" :key="a"><th class="py-1 pr-2" x-text="a"></th></template>
                                                <th class="py-1 pr-2 w-20">Qty</th><th class="w-6"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="(v, vi) in item.variants" :key="vi">
                                                <tr>
                                                    <template x-for="a in item.attribute_names" :key="a">
                                                        <td class="py-1 pr-2"><input x-model="v.attrs[a]" class="input py-1 text-sm" :placeholder="a"></td>
                                                    </template>
                                                    <td class="py-1 pr-2"><input x-model.number="v.qty" type="number" min="0" class="input py-1 text-sm w-20"></td>
                                                    <td><button type="button" @click="removeVariant(item, vi)" class="text-red-600 text-sm">×</button></td>
                                                </tr>
                                            </template>
                                            <tr x-show="!item.attribute_names.length">
                                                <td class="py-1 pr-2 text-xs text-ink-700/40" colspan="2">No attributes — add one above, or just set a quantity:</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                {{-- Hidden serialised attribute/variant data --}}
                                <input type="hidden" :name="`items[${i}][attribute_names_json]`" :value="JSON.stringify(item.attribute_names)">
                                <input type="hidden" :name="`items[${i}][variants_json]`" :value="JSON.stringify(item.variants)">
                                @if($order->exists)<input type="hidden" :name="`items[${i}][received_qty]`" :value="item.received_qty || 0">@endif
                            </div>
                        </div>
                    </template>
                </div>

                <div class="flex justify-between items-center mt-3 pt-3 border-t border-ink-100 text-sm">
                    <span class="text-ink-700/60">Total qty: <strong x-text="totalQty"></strong></span>
                    <span class="font-medium">Subtotal: <span x-text="currency"></span> <span x-text="itemsSubtotal.toFixed(2)"></span> <span class="text-ink-700/50" x-show="rate > 0">≈ ৳<span x-text="(itemsSubtotal * rate).toFixed(0)"></span></span></span>
                </div>

                <datalist id="po-products">
                    @foreach($products as $p)<option value="{{ $p->name }}">{{ $p->sku }}</option>@endforeach
                </datalist>
            </div>
        </div>

        {{-- Sidebar: costs + totals --}}
        <div class="space-y-6">
            <div class="card p-5 lg:sticky lg:top-4">
                <h2 class="font-semibold mb-3">Costs &amp; shipping</h2>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label">Currency</label><input name="currency" x-model="currency" class="input" placeholder="USD"></div>
                    <div><label class="label">Rate → BDT</label><input name="exchange_rate" x-model.number="rate" type="number" step="0.0001" min="0" class="input" placeholder="e.g. 122"></div>
                </div>
                <div class="mt-2"><label class="label">Ship to <span class="font-normal text-ink-700/50">(printed on the PDF)</span></label><textarea name="ship_to" rows="3" class="input" placeholder="Forwarder / warehouse name, address, contact…">{{ old('ship_to', $order->ship_to) }}</textarea></div>
                <div class="mt-2"><label class="label">Courier / shipping name</label><input name="courier_name" value="{{ old('courier_name', $order->courier_name) }}" class="input" placeholder="Pathao, FedEx…"></div>
                <div class="mt-2"><label class="label">Tracking &amp; shipping details</label><textarea name="courier_tracking" rows="4" class="input" placeholder="Tracking numbers, shipping address, forwarder notes…">{{ old('courier_tracking', $order->courier_tracking) }}</textarea></div>
                <div class="grid grid-cols-2 gap-2 mt-2">
                    <div><label class="label">Courier cost</label><input name="courier_cost" x-model.number="courier" type="number" step="0.01" min="0" class="input"></div>
                    <div><label class="label">Processing %</label><input name="processing_pct" x-model.number="processing" type="number" step="0.01" min="0" class="input"></div>
                </div>

                <dl class="mt-4 space-y-1.5 text-sm border-t border-ink-100 pt-3">
                    <div class="flex justify-between"><dt class="text-ink-700/60">Items</dt><dd><span x-text="currency"></span> <span x-text="itemsSubtotal.toFixed(2)"></span></dd></div>
                    <div class="flex justify-between"><dt class="text-ink-700/60">Courier</dt><dd><span x-text="currency"></span> <span x-text="(courier||0).toFixed(2)"></span></dd></div>
                    <div class="flex justify-between"><dt class="text-ink-700/60">Processing</dt><dd><span x-text="currency"></span> <span x-text="processingFee.toFixed(2)"></span></dd></div>
                    <div class="flex justify-between font-semibold text-base border-t border-ink-100 pt-2"><dt>Total</dt><dd><span x-text="currency"></span> <span x-text="grandTotal.toFixed(2)"></span></dd></div>
                    <div class="flex justify-between text-xs text-ink-700/50" x-show="rate > 0"><dt>≈ in BDT</dt><dd>৳<span x-text="(grandTotal * rate).toFixed(0)"></span></dd></div>
                </dl>

                <div class="mt-3"><label class="label">Notes</label><textarea name="notes" rows="3" class="input" placeholder="MOQ, payment terms, packaging…">{{ old('notes', $order->notes) }}</textarea></div>

                <button class="btn-primary w-full mt-4" x-text="exists ? 'Save & close' : 'Create purchase order'">{{ $order->exists ? 'Save & close' : 'Create purchase order' }}</button>
                <p class="text-center text-xs mt-2" :class="saveTone" x-text="saveLabel"></p>
            </div>
        </div>
    </div>
    {{-- Floating add button: reachable from anywhere down a long order. --}}
    <button type="button" @click="addItemAndFocus()"
            class="btn-primary fixed bottom-6 left-1/2 -translate-x-1/2 z-30 rounded-full shadow-lg px-5 py-3 text-base font-semibold">+ Add product</button>
</form>

<script>
    function poForm(initial, exists) {
        return {
            items: initial,
            // Autosave: every edit is posted in the background (debounced) to the
            // same store/update routes as JSON. A new PO is created on its first
            // autosave, after which the form keeps saving to that record.
            exists,
            state: exists ? 'saved' : 'new', // new | idle | pending | saving | saved | error
            savedAt: '',
            saveError: '',
            timer: null,
            saving: false,
            dirty: false,
            submitAfterSave: false,
            init() {
                this.$watch('items', () => this.queueSave());
                window.addEventListener('beforeunload', () => { if (this.dirty && !this.saving) this.save(true); });
            },
            get saveLabel() {
                return {
                    new: 'Autosaves as you edit',
                    idle: 'Choose a supplier to start autosaving',
                    pending: 'Unsaved changes…',
                    saving: 'Saving…',
                    saved: this.savedAt ? 'All changes saved · ' + this.savedAt : 'All changes saved',
                    error: 'Not saved — ' + this.saveError,
                }[this.state];
            },
            get saveTone() { return this.state === 'error' ? 'text-red-600' : (this.state === 'saved' ? 'text-green-700' : 'text-ink-700/50'); },
            queueSave() {
                this.dirty = true;
                if (!this.$refs.form.supplier_id.value) { this.state = 'idle'; return; }
                this.state = 'pending';
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.save(), 1200);
            },
            async save(keepalive = false) {
                clearTimeout(this.timer);
                if (!this.$refs.form.supplier_id.value) return;
                if (this.saving) return; // the in-flight save re-runs when it finishes
                this.saving = true; this.dirty = false; this.state = 'saving';
                try {
                    const res = await fetch(this.$refs.form.action, {
                        method: 'POST', keepalive,
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(this.$refs.form),
                    });
                    const data = await res.json().catch(() => ({}));
                    if (res.ok && data.ok) {
                        if (!this.exists) {
                            this.exists = true;
                            this.$refs.form.action = data.update_url;
                            if (!this.$refs.poNumber.value) this.$refs.poNumber.value = data.po_number;
                            if (!this.$refs.orderedAt.value) this.$refs.orderedAt.value = data.ordered_at || '';
                            history.replaceState(null, '', data.edit_url);
                        }
                        this.savedAt = data.saved_at; this.state = 'saved';
                    } else {
                        this.dirty = true; this.state = 'error';
                        this.saveError = res.status === 419 ? 'session expired, reload the page'
                            : (data.errors ? Object.values(data.errors)[0][0] : (data.message || 'server error ' + res.status));
                    }
                } catch (e) {
                    this.dirty = true; this.state = 'error'; this.saveError = 'connection lost, retrying';
                    this.timer = setTimeout(() => this.save(), 8000);
                }
                this.saving = false;
                if (this.submitAfterSave) { this.submitAfterSave = false; this.$nextTick(() => this.$refs.form.submit()); return; }
                if (this.dirty && this.state !== 'error') this.save();
            },
            onSubmit(e) {
                clearTimeout(this.timer);
                // A create still in flight would be duplicated by the normal POST — wait for it.
                if (this.saving) { e.preventDefault(); this.submitAfterSave = true; }
            },
            attrPresets: ['Logo', 'Color', 'Size', 'Finish', 'Material', 'Style'],
            currency: '{{ old('currency', $order->currency ?: 'USD') }}',
            rate: {{ old('exchange_rate', $order->exchange_rate ?: 0) }},
            courier: {{ old('courier_cost', $order->courier_cost ?: 0) }},
            processing: {{ old('processing_pct', $order->processing_pct ?: 0) }},
            addItem() { this.items.push({ product_name: '', sku: '', unit_cost: 0, target_price: null, received_qty: 0, product_link: '', image_url: '', attribute_names: [], variants: [{ attrs: {}, qty: 1 }] }); },
            addItemAndFocus() {
                this.addItem();
                this.focusItem(this.items.length - 1);
            },
            // Copy a product (links, image, prices, attributes, variant rows) into
            // a new row right below it; nothing of it has been received yet.
            duplicateItem(i) {
                const copy = JSON.parse(JSON.stringify(this.items[i]));
                copy.received_qty = 0;
                this.items.splice(i + 1, 0, copy);
                this.focusItem(i + 1, true);
            },
            focusItem(index, select = false) {
                this.$nextTick(() => {
                    const card = document.querySelectorAll('[data-po-item]')[index];
                    if (!card) return;
                    card.scrollIntoView({ block: 'center' });
                    const name = card.querySelector('[placeholder="Product name *"]');
                    name?.focus({ preventScroll: true });
                    if (select) name?.select();
                });
            },
            removeItem(i) { this.items.splice(i, 1); if (!this.items.length) this.addItem(); },
            addAttr(item, name) { if (!item.attribute_names.includes(name)) { item.attribute_names.push(name); item.variants.forEach(v => { if (!(name in v.attrs)) v.attrs[name] = ''; }); } },
            addCustomAttr(item) { const n = (prompt('Attribute name (e.g. Length)') || '').trim(); if (n) this.addAttr(item, n); },
            removeAttr(item, name) { item.attribute_names = item.attribute_names.filter(a => a !== name); item.variants.forEach(v => { delete v.attrs[name]; }); },
            addVariant(item) { const attrs = {}; item.attribute_names.forEach(a => attrs[a] = ''); item.variants.push({ attrs, qty: 1 }); },
            removeVariant(item, vi) { item.variants.splice(vi, 1); if (!item.variants.length) this.addVariant(item); },
            itemQty(item) { return (item.variants || []).reduce((s, v) => s + (Number(v.qty) || 0), 0); },
            async fetchImg(item) {
                if (!item.product_link) return;
                try {
                    const res = await fetch('{{ route('admin.purchase-orders.fetch-image') }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                        body: JSON.stringify({ url: item.product_link })
                    });
                    const data = await res.json();
                    if (data.ok && data.image) item.image_url = data.image;
                    else alert('Could not auto-fetch an image from that link. Paste the image URL manually.');
                } catch (e) { alert('Auto-fetch failed.'); }
            },
            get totalQty() { return this.items.reduce((s, it) => s + this.itemQty(it), 0); },
            get itemsSubtotal() { return this.items.reduce((s, it) => s + this.itemQty(it) * (Number(it.unit_cost) || 0), 0); },
            get processingFee() { return (this.itemsSubtotal + (Number(this.courier) || 0)) * ((Number(this.processing) || 0) / 100); },
            get grandTotal() { return this.itemsSubtotal + (Number(this.courier) || 0) + this.processingFee; },
        };
    }
</script>
@endsection
