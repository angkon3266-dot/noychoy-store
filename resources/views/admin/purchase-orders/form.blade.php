@extends('layouts.admin')
@section('title', $order->exists ? 'Edit purchase order' : 'New purchase order')
@section('heading', $order->exists ? 'Edit PO · '.$order->po_number : 'New purchase order')

@section('content')
@if($errors->any())<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm">{{ $errors->first() }}</div>@endif

<form method="POST" action="{{ $order->exists ? route('admin.purchase-orders.update', $order) : route('admin.purchase-orders.store') }}"
      x-data="poForm({{ Illuminate\Support\Js::from($initialItems) }})">
    @csrf
    @if($order->exists) @method('PUT') @endif

    <div class="grid lg:grid-cols-3 gap-6">
        {{-- Main: supplier + items --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="card p-5">
                <h2 class="font-semibold mb-3">Order details</h2>
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
                        <input name="po_number" value="{{ old('po_number', $order->po_number) }}" class="input" placeholder="Auto-generated if blank">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label">Ordered</label><input type="date" name="ordered_at" value="{{ old('ordered_at', $order->ordered_at?->format('Y-m-d')) }}" class="input"></div>
                        <div><label class="label">Expected</label><input type="date" name="expected_at" value="{{ old('expected_at', $order->expected_at?->format('Y-m-d')) }}" class="input"></div>
                    </div>
                </div>
            </div>

            {{-- Line items --}}
            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-semibold">Items</h2>
                    <button type="button" @click="addItem()" class="btn-outline text-sm py-1.5">+ Add item</button>
                </div>
                <div class="space-y-3">
                    <template x-for="(item, i) in items" :key="i">
                        <div class="rounded-lg border border-ink-100 p-3">
                            <div class="grid sm:grid-cols-12 gap-2">
                                <input :name="`items[${i}][product_name]`" x-model="item.product_name" class="input sm:col-span-5" placeholder="Product name *" list="po-products">
                                <input :name="`items[${i}][sku]`" x-model="item.sku" class="input sm:col-span-3" placeholder="SKU">
                                <input :name="`items[${i}][qty]`" x-model.number="item.qty" type="number" min="0" class="input sm:col-span-2" placeholder="Qty">
                                <input :name="`items[${i}][unit_cost]`" x-model.number="item.unit_cost" type="number" step="0.01" min="0" class="input sm:col-span-2" placeholder="Unit cost">
                            </div>
                            <div class="grid sm:grid-cols-12 gap-2 mt-2">
                                <input :name="`items[${i}][color]`" x-model="item.color" class="input sm:col-span-2" placeholder="Color">
                                <input :name="`items[${i}][size]`" x-model="item.size" class="input sm:col-span-2" placeholder="Size">
                                <input :name="`items[${i}][product_link]`" x-model="item.product_link" class="input sm:col-span-4" placeholder="Product link (Alibaba…)">
                                <input :name="`items[${i}][image_url]`" x-model="item.image_url" class="input sm:col-span-3" placeholder="Image URL">
                                <button type="button" @click="removeItem(i)" class="sm:col-span-1 text-red-600 hover:bg-red-50 rounded text-sm">✕</button>
                            </div>
                            @if($order->exists)
                                <div class="mt-2 flex items-center gap-2 text-xs text-ink-700/60">
                                    <span>Received:</span>
                                    <input :name="`items[${i}][received_qty]`" x-model.number="item.received_qty" type="number" min="0" class="input py-1 w-20 text-sm">
                                </div>
                            @endif
                            <div class="mt-1 text-right text-xs text-ink-700/50">Line: <span x-text="(item.qty * item.unit_cost).toFixed(2)"></span></div>
                        </div>
                    </template>
                </div>
                <datalist id="po-products">
                    @foreach($products as $p)<option value="{{ $p->name }}">{{ $p->sku }}</option>@endforeach
                </datalist>
            </div>
        </div>

        {{-- Sidebar: costs + totals --}}
        <div class="space-y-6">
            <div class="card p-5 sticky top-4">
                <h2 class="font-semibold mb-3">Costs &amp; shipping</h2>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label">Currency</label><input name="currency" x-model="currency" class="input" placeholder="USD"></div>
                    <div><label class="label">Rate → BDT</label><input name="exchange_rate" x-model.number="rate" type="number" step="0.0001" min="0" class="input" placeholder="e.g. 122"></div>
                </div>
                <div class="mt-2"><label class="label">Courier / shipping name</label><input name="courier_name" value="{{ old('courier_name', $order->courier_name) }}" class="input" placeholder="Pathao, FedEx…"></div>
                <div class="mt-2"><label class="label">Courier tracking</label><input name="courier_tracking" value="{{ old('courier_tracking', $order->courier_tracking) }}" class="input"></div>
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

                <button class="btn-primary w-full mt-4">{{ $order->exists ? 'Save purchase order' : 'Create purchase order' }}</button>
                @if($order->exists)<a href="{{ route('admin.purchase-orders.show', $order) }}" class="block text-center text-sm text-ink-700/60 hover:underline mt-2">Cancel</a>@endif
            </div>
        </div>
    </div>
</form>

<script>
    function poForm(initial) {
        return {
            items: initial,
            currency: '{{ old('currency', $order->currency ?: 'USD') }}',
            rate: {{ old('exchange_rate', $order->exchange_rate ?: 0) }},
            courier: {{ old('courier_cost', $order->courier_cost ?: 0) }},
            processing: {{ old('processing_pct', $order->processing_pct ?: 0) }},
            addItem() { this.items.push({ product_name: '', sku: '', qty: 1, unit_cost: 0, color: '', size: '', product_link: '', image_url: '', received_qty: 0 }); },
            removeItem(i) { this.items.splice(i, 1); if (!this.items.length) this.addItem(); },
            get itemsSubtotal() { return this.items.reduce((s, it) => s + (Number(it.qty) || 0) * (Number(it.unit_cost) || 0), 0); },
            get processingFee() { return (this.itemsSubtotal + (Number(this.courier) || 0)) * ((Number(this.processing) || 0) / 100); },
            get grandTotal() { return this.itemsSubtotal + (Number(this.courier) || 0) + this.processingFee; },
        };
    }
</script>
@endsection
