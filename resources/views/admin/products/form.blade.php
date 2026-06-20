@extends('layouts.admin')
@section('title', $product->exists ? 'Edit product' : 'Add product')
@section('heading', $product->exists ? 'Edit product' : 'Add product')

@section('content')
@if($errors->any())
    <div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm mb-4">
        <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<form action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}"
      method="POST" enctype="multipart/form-data"
      x-data="{
          variants: {{ Js::from($product->variants->map(fn($v)=>['label'=>$v->attributes['Option']??'','sku'=>$v->sku,'price'=>$v->price,'stock'=>$v->stock_quantity])->values()) }},
          offers: {{ Js::from(collect(old('quantity_offers', $product->quantity_offers ?? []))->map(fn($t)=>['min_qty'=>$t['min_qty']??'','percent'=>$t['percent']??''])->values()) }}
      }">
    @csrf
    @if($product->exists) @method('PUT') @endif

    <div class="grid lg:grid-cols-3 gap-6">
        <!-- main -->
        <div class="lg:col-span-2 space-y-6">
            <div class="card p-6 space-y-4">
                <div>
                    <label class="label">Product name *</label>
                    <input name="name" value="{{ old('name', $product->name) }}" class="input" required>
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div><label class="label">SKU</label><input name="sku" value="{{ old('sku', $product->sku) }}" class="input"></div>
                    <div>
                        <label class="label">Category</label>
                        <select name="category_id" class="input">
                            <option value="">— None —</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" @selected(old('category_id', $product->category_id)==$cat->id)>{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="label">Short description</label>
                    <textarea name="short_description" rows="2" class="input">{{ old('short_description', $product->short_description) }}</textarea>
                </div>
                <div>
                    <label class="label">Full description</label>
                    <textarea name="description" rows="6" class="input">{{ old('description', $product->description) }}</textarea>
                </div>
            </div>

            <!-- Pricing -->
            <div class="card p-6 space-y-4"
                 x-data="{
                    price: {{ (float) old('price', $product->price ?: 0) }},
                    cost: {{ (float) old('cost_price', $product->cost_price ?: 0) }},
                    transport: {{ (float) old('transport_cost', $product->transport_cost ?: 0) }},
                    get profit() { return this.price - this.cost - this.transport; },
                    get margin() { return this.price > 0 ? (this.profit / this.price * 100) : 0; },
                    fmt(n) { return '৳' + Number(n).toLocaleString('en-BD', {maximumFractionDigits: 2}); }
                 }">
                <h2 class="font-semibold">Pricing &amp; stock</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div><label class="label">Price (৳) *</label><input name="price" type="number" step="0.01" x-model.number="price" value="{{ old('price', $product->price) }}" class="input" required></div>
                    <div><label class="label">Compare-at (৳)</label><input name="compare_at_price" type="number" step="0.01" value="{{ old('compare_at_price', $product->compare_at_price) }}" class="input"></div>
                    <div><label class="label">Product cost (৳)</label><input name="cost_price" type="number" step="0.01" x-model.number="cost" value="{{ old('cost_price', $product->cost_price) }}" class="input" placeholder="What you pay supplier"></div>
                    <div><label class="label">Transport / packaging (৳)</label><input name="transport_cost" type="number" step="0.01" x-model.number="transport" value="{{ old('transport_cost', $product->transport_cost) }}" class="input" placeholder="Inbound + packing per unit"></div>
                </div>

                {{-- Live margin readout --}}
                <div class="rounded-lg border p-3 flex flex-wrap items-center gap-x-6 gap-y-1 text-sm"
                     :class="profit < 0 ? 'border-red-200 bg-red-50' : (margin < 20 ? 'border-amber-200 bg-amber-50' : 'border-green-200 bg-green-50')">
                    <span class="text-ink-700/60">Landed cost: <strong x-text="fmt(cost + transport)"></strong></span>
                    <span class="text-ink-700/60">Profit / unit: <strong x-text="fmt(profit)" :class="profit < 0 ? 'text-red-700' : 'text-green-700'"></strong></span>
                    <span class="text-ink-700/60">Margin: <strong x-text="margin.toFixed(1) + '%'" :class="profit < 0 ? 'text-red-700' : 'text-green-700'"></strong></span>
                    <span x-show="cost === 0 && transport === 0" class="text-ink-700/40 text-xs">Enter cost to see margin</span>
                </div>

                <div class="flex flex-wrap items-end gap-4">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="manage_stock" value="1" @checked(old('manage_stock', $product->manage_stock))> Track stock</label>
                    <div><label class="label">Stock quantity</label><input name="stock_quantity" type="number" value="{{ old('stock_quantity', $product->stock_quantity) }}" class="input w-32"></div>
                </div>
            </div>

            <!-- Variants -->
            <div class="card p-6">
                <div class="flex items-center justify-between mb-3">
                    <div><h2 class="font-semibold">Variants</h2><p class="text-xs text-ink-700/60">Optional — e.g. ring sizes or colors. Leave empty for a simple product.</p></div>
                    <button type="button" @click="variants.push({label:'',sku:'',price:'',stock:0})" class="btn-outline py-1.5">+ Add variant</button>
                </div>
                <template x-if="variants.length">
                    <div class="space-y-2">
                        <div class="grid grid-cols-12 gap-2 text-xs text-ink-700/60 px-1">
                            <span class="col-span-5">Option (e.g. Size 6 / Gold)</span><span class="col-span-3">SKU</span><span class="col-span-2">Price ৳</span><span class="col-span-1">Stock</span>
                        </div>
                        <template x-for="(v, i) in variants" :key="i">
                            <div class="grid grid-cols-12 gap-2 items-center">
                                <input :name="`variants[${i}][label]`" x-model="v.label" class="input py-2 col-span-5" placeholder="Size 6 / Gold">
                                <input :name="`variants[${i}][sku]`" x-model="v.sku" class="input py-2 col-span-3">
                                <input :name="`variants[${i}][price]`" x-model="v.price" type="number" step="0.01" class="input py-2 col-span-2" placeholder="base">
                                <input :name="`variants[${i}][stock]`" x-model="v.stock" type="number" class="input py-2 col-span-1">
                                <button type="button" @click="variants.splice(i,1)" class="col-span-1 text-red-600 text-lg">×</button>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <!-- Quantity / bundle offers -->
            <div class="card p-6">
                <div class="flex items-center justify-between mb-3">
                    <div><h2 class="font-semibold">Quantity offers</h2><p class="text-xs text-ink-700/60">e.g. “Buy 2 get 5% off”. Applies automatically in cart &amp; checkout.</p></div>
                    <button type="button" @click="offers.push({min_qty:'',percent:''})" class="btn-outline py-1.5">+ Add offer</button>
                </div>
                <template x-if="offers.length">
                    <div class="space-y-2">
                        <div class="grid grid-cols-12 gap-2 text-xs text-ink-700/60 px-1">
                            <span class="col-span-5">Buy quantity (min)</span><span class="col-span-5">Discount %</span>
                        </div>
                        <template x-for="(o, i) in offers" :key="i">
                            <div class="grid grid-cols-12 gap-2 items-center">
                                <input :name="`quantity_offers[${i}][min_qty]`" x-model="o.min_qty" type="number" min="2" class="input py-2 col-span-5" placeholder="2">
                                <input :name="`quantity_offers[${i}][percent]`" x-model="o.percent" type="number" step="0.01" min="0.1" max="90" class="input py-2 col-span-5" placeholder="5">
                                <button type="button" @click="offers.splice(i,1)" class="col-span-2 text-red-600 text-lg">×</button>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="!offers.length"><p class="text-sm text-ink-700/50">No quantity offers. Click “Add offer” to create tiered pricing.</p></template>
            </div>

            <!-- Upsell / cross-sell -->
            <div class="card p-6 space-y-4">
                <div><h2 class="font-semibold">Related products</h2><p class="text-xs text-ink-700/60">Drive bigger orders with manual recommendations. Ctrl/Cmd-click to select several.</p></div>
                @php
                    $selUp = old('upsell_ids', $product->upsell_ids ?? []);
                    $selCross = old('cross_sell_ids', $product->cross_sell_ids ?? []);
                @endphp
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">“You may also like” (upsells)</label>
                        <select name="upsell_ids[]" multiple size="6" class="input">
                            @foreach($allProducts as $p)
                                <option value="{{ $p->id }}" @selected(in_array($p->id, (array) $selUp))>{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">“Frequently bought together” (cross-sells)</label>
                        <select name="cross_sell_ids[]" multiple size="6" class="input">
                            @foreach($allProducts as $p)
                                <option value="{{ $p->id }}" @selected(in_array($p->id, (array) $selCross))>{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- sidebar -->
        <div class="space-y-6">
            <div class="card p-6 space-y-4">
                <div>
                    <label class="label">Status</label>
                    <select name="status" class="input">
                        <option value="published" @selected(old('status', $product->status)=='published')>Published</option>
                        <option value="draft" @selected(old('status', $product->status)=='draft')>Draft</option>
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $product->is_featured))> Featured on homepage</label>
                <button class="btn-primary w-full">{{ $product->exists ? 'Save changes' : 'Create product' }}</button>
            </div>

            <!-- Pre-order -->
            <div class="card p-6 space-y-4" x-data="{ pre: {{ old('is_preorder', $product->is_preorder) ? 'true' : 'false' }} }">
                <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" name="is_preorder" value="1" x-model="pre" @checked(old('is_preorder', $product->is_preorder))> 📅 Pre-order item</label>
                <p class="text-xs text-ink-700/60 -mt-2">Shows a "Book now" button instead of the normal buy button. Sellable even with zero stock. Tip: you can also flag a whole category as pre-order under Categories.</p>
                <div x-show="pre" x-cloak class="space-y-3">
                    <div>
                        <label class="label">Expected availability date</label>
                        <input name="preorder_release_date" type="date" value="{{ old('preorder_release_date', optional($product->preorder_release_date)->format('Y-m-d')) }}" class="input">
                    </div>
                    <div>
                        <label class="label">Pre-order note (shown on product page)</label>
                        <input name="preorder_note" value="{{ old('preorder_note', $product->preorder_note) }}" class="input" placeholder="e.g. Reserve now, ships in 2 weeks">
                    </div>
                </div>
            </div>

            <!-- Images -->
            <div class="card p-6">
                <h2 class="font-semibold mb-3">Images</h2>
                @if($product->exists && $product->images->isNotEmpty())
                    <p class="text-xs text-ink-700/50 mb-2">Drag to reorder. The ★ image is the primary (shown first).</p>
                    <div id="imgGrid" class="grid grid-cols-3 gap-2 mb-3">
                        @foreach($product->images as $image)
                            <div class="img-card relative group cursor-move" draggable="true" data-img-id="{{ $image->id }}">
                                <img src="{{ $image->url }}" class="aspect-square w-full object-cover rounded-lg pointer-events-none {{ $image->is_primary ? 'ring-2 ring-gold-500' : '' }}" alt="">
                                @if($image->is_primary)<span class="absolute top-1 left-1 text-xs bg-gold-500 text-white rounded px-1">★</span>@endif
                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-1 rounded-lg">
                                    @unless($image->is_primary)
                                        <form action="{{ route('admin.products.images.primary', $image) }}" method="POST">@csrf<button class="text-[10px] bg-white rounded px-1.5 py-0.5">Primary</button></form>
                                    @endunless
                                    <form action="{{ route('admin.products.images.delete', $image) }}" method="POST">@csrf @method('DELETE')<button class="text-[10px] bg-red-600 text-white rounded px-1.5 py-0.5">Del</button></form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div id="imgOrderInputs"></div>
                @endif
                <input type="file" name="images[]" multiple accept="image/*" class="input text-sm">
                <p class="text-xs text-ink-700/50 mt-2">Upload JPG/PNG/WebP. First uploaded becomes primary if none set.</p>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    const grid = document.getElementById('imgGrid');
    if (!grid) return;
    const form = grid.closest('form');
    const out = document.getElementById('imgOrderInputs');
    let dragEl = null;

    grid.querySelectorAll('.img-card').forEach(card => {
        card.addEventListener('dragstart', () => { dragEl = card; card.classList.add('opacity-40'); });
        card.addEventListener('dragend', () => { card.classList.remove('opacity-40'); });
        card.addEventListener('dragover', e => {
            e.preventDefault();
            if (!dragEl || dragEl === card) return;
            const rect = card.getBoundingClientRect();
            const after = (e.clientY - rect.top) > rect.height / 2 || (e.clientX - rect.left) > rect.width / 2;
            grid.insertBefore(dragEl, after ? card.nextSibling : card);
        });
    });

    // Serialise the current order into hidden inputs right before submit.
    form.addEventListener('submit', () => {
        out.innerHTML = '';
        grid.querySelectorAll('.img-card').forEach(card => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'image_order[]';
            input.value = card.dataset.imgId;
            out.appendChild(input);
        });
    });
})();
</script>
@endsection
