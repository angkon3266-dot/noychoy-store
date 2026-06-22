@extends('layouts.admin')
@section('title', $product->exists ? 'Edit product' : 'Add product')
@section('heading', $product->exists ? 'Edit product' : 'Add product')

@section('content')
@if($errors->any())
    <div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm mb-4">
        <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@php
    $vType = old('product_type', $product->has_variants ? 'variable' : 'simple');
    $vAttributes = collect(old('attributes', collect($product->options ?? [])->map(fn ($o) => ['name' => $o['name'] ?? '', 'values' => implode(', ', $o['values'] ?? [])])->all()))->values();
    $vVariants = collect(old('variants', $product->variants->map(fn ($v) => ['attrs' => $v->attributes ?? [], 'price' => $v->price, 'stock' => $v->stock_quantity, 'sku' => $v->sku])->all()))->values();
    $formConfig = [
        'type' => $vType,
        'attributes' => $vAttributes,
        'variants' => $vVariants,
        'offers' => collect(old('quantity_offers', $product->quantity_offers ?? []))->map(fn ($t) => ['min_qty' => $t['min_qty'] ?? '', 'percent' => $t['percent'] ?? ''])->values(),
        'price' => (float) old('price', $product->price ?: 0),
        'cost' => (float) old('cost_price', $product->cost_price ?: 0),
        'transport' => (float) old('transport_cost', $product->transport_cost ?: 0),
    ];
@endphp
<form action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}"
      method="POST" enctype="multipart/form-data" x-data="productForm(@js($formConfig))">
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
                        <label class="label">Primary category</label>
                        <select name="category_id" class="input" onchange="primaryCatChanged(this.value)">
                            <option value="">— None —</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" @selected(old('category_id', $product->category_id)==$cat->id)>{{ $cat->name }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-ink-700/50 mt-1">Used for the breadcrumb &amp; page template. Selecting it auto-ticks its sub-categories below.</p>
                    </div>
                </div>

                {{-- Multiple categories (for browsing + Meta catalog ad sets) --}}
                @php
                    $existingCats = $product->exists ? $product->categories->pluck('id')->all() : [];
                    $selectedCats = collect(old('category_ids', $existingCats))->map(fn ($i) => (int) $i)->all();
                @endphp
                <div x-data="{ q: '' }">
                    <label class="label">Categories (a product can be in several)</label>
                    <input x-model="q" placeholder="Filter categories…" class="input py-1.5 mb-2">
                    <div class="max-h-44 overflow-y-auto rounded-lg border border-ink-100 p-2 grid sm:grid-cols-2 gap-1">
                        @foreach($categories as $cat)
                            <label class="flex items-center gap-2 text-sm py-1 px-1 rounded hover:bg-ink-50"
                                   x-show="q==='' || '{{ Str::lower($cat->name) }}'.includes(q.toLowerCase())">
                                <input type="checkbox" name="category_ids[]" value="{{ $cat->id }}" @checked(in_array($cat->id, $selectedCats))>
                                <span>{{ $cat->parent_id ? '— ' : '' }}{{ $cat->name }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="text-xs text-ink-700/50 mt-1">Tick every category this piece belongs to — this powers category-based Meta catalog ads later.</p>
                </div>
                <script>
                    window.__catChildren = @json($categories->groupBy('parent_id')->map(fn($g) => $g->pluck('id'))->all());
                    function primaryCatChanged(id) {
                        if (!id) return;
                        const ids = [parseInt(id, 10), ...((window.__catChildren[id] || []))];
                        ids.forEach(cid => {
                            const cb = document.querySelector(`input[name='category_ids[]'][value='${cid}']`);
                            if (cb) cb.checked = true;
                        });
                    }
                </script>
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
            <div class="card p-6 space-y-4">
                <h2 class="font-semibold">Pricing &amp; stock</h2>

                {{-- Product type --}}
                <div>
                    <span class="label">Product type</span>
                    <div class="flex gap-2">
                        <label class="flex-1 cursor-pointer rounded-md border px-4 py-3 text-sm" :class="type==='simple' ? 'border-gold-500 bg-gold-50' : 'border-ink-100'">
                            <input type="radio" name="product_type" value="simple" x-model="type" class="mr-1"> Simple product
                            <span class="block text-xs text-ink-700/50 mt-0.5">One price, one SKU.</span>
                        </label>
                        <label class="flex-1 cursor-pointer rounded-md border px-4 py-3 text-sm" :class="type==='variable' ? 'border-gold-500 bg-gold-50' : 'border-ink-100'">
                            <input type="radio" name="product_type" value="variable" x-model="type" class="mr-1"> Variable product
                            <span class="block text-xs text-ink-700/50 mt-0.5">Options (size, colour) each with its own price &amp; stock.</span>
                        </label>
                    </div>
                </div>

                {{-- Simple-only price (hidden for variable; price comes from variations) --}}
                <div class="grid sm:grid-cols-2 gap-4" x-show="type==='simple'">
                    <div><label class="label">Price (৳) *</label><input name="price" type="number" step="0.01" x-model.number="price" :required="type==='simple'" class="input"></div>
                    <div><label class="label">Compare-at (৳)</label><input name="compare_at_price" type="number" step="0.01" value="{{ old('compare_at_price', $product->compare_at_price) }}" class="input"></div>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div><label class="label">Product cost (৳)</label><input name="cost_price" type="number" step="0.01" x-model.number="cost" class="input" placeholder="What you pay supplier"></div>
                    <div><label class="label">Transport / packaging (৳)</label><input name="transport_cost" type="number" step="0.01" x-model.number="transport" class="input" placeholder="Inbound + packing per unit"></div>
                </div>

                {{-- Live margin readout --}}
                <div class="rounded-lg border p-3 flex flex-wrap items-center gap-x-6 gap-y-1 text-sm"
                     :class="profit < 0 ? 'border-red-200 bg-red-50' : (margin < 20 ? 'border-amber-200 bg-amber-50' : 'border-green-200 bg-green-50')">
                    <span class="text-ink-700/60">Landed cost: <strong x-text="fmt(cost + transport)"></strong></span>
                    <span class="text-ink-700/60">Profit / unit: <strong x-text="fmt(profit)" :class="profit < 0 ? 'text-red-700' : 'text-green-700'"></strong></span>
                    <span class="text-ink-700/60">Margin: <strong x-text="margin.toFixed(1) + '%'" :class="profit < 0 ? 'text-red-700' : 'text-green-700'"></strong></span>
                    <span x-show="type==='variable'" class="text-ink-700/40 text-xs">Margin uses the simple price; per-variation prices set below.</span>
                </div>

                <div class="flex flex-wrap items-end gap-4" x-show="type==='simple'">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="manage_stock" value="1" @checked(old('manage_stock', $product->manage_stock))> Track stock</label>
                    <div><label class="label">Stock quantity</label><input name="stock_quantity" type="number" value="{{ old('stock_quantity', $product->stock_quantity) }}" class="input w-32"></div>
                </div>
            </div>

            <!-- Variations (variable products only) -->
            <div class="card p-6" x-show="type==='variable'" x-cloak>
                <h2 class="font-semibold mb-1">Variations</h2>
                <p class="text-xs text-ink-700/60 mb-4">Define options like <strong>Size</strong> and <strong>Colour</strong>, then set a price &amp; stock for each combination.</p>

                {{-- Attributes --}}
                <div class="space-y-2 mb-4">
                    <template x-for="(a, i) in attributes" :key="i">
                        <div class="grid grid-cols-12 gap-2 items-center">
                            <input :name="`attributes[${i}][name]`" x-model="a.name" @change="generate()" class="input py-2 col-span-4" placeholder="Option name (e.g. Size)">
                            <input :name="`attributes[${i}][values]`" x-model="a.values" @change="generate()" @blur="generate()" class="input py-2 col-span-7" placeholder="Comma-separated values (e.g. 6, 7, 8)">
                            <button type="button" @click="removeAttribute(i)" class="col-span-1 text-red-600 text-lg">×</button>
                        </div>
                    </template>
                    <button type="button" @click="addAttribute()" class="btn-outline py-1.5">+ Add option (Size, Colour…)</button>
                </div>

                {{-- Generated variation matrix --}}
                <template x-if="variants.length">
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium"><span x-text="variants.length"></span> variation(s)</p>
                            <button type="button" @click="generate()" class="text-xs text-gold-700 hover:underline">↻ Regenerate</button>
                        </div>
                        <div class="grid grid-cols-12 gap-2 text-xs text-ink-700/60 px-1">
                            <span class="col-span-5">Variation</span><span class="col-span-3">Price ৳</span><span class="col-span-2">Stock</span><span class="col-span-2">SKU</span>
                        </div>
                        <template x-for="(v, i) in variants" :key="keyOf(v.attrs)">
                            <div class="grid grid-cols-12 gap-2 items-center">
                                <span class="col-span-5 text-sm" x-text="label(v.attrs)"></span>
                                <input :name="`variants[${i}][price]`" x-model="v.price" type="number" step="0.01" class="input py-2 col-span-3" placeholder="0.00">
                                <input :name="`variants[${i}][stock]`" x-model="v.stock" type="number" class="input py-2 col-span-2" placeholder="0">
                                <input :name="`variants[${i}][sku]`" x-model="v.sku" class="input py-2 col-span-2" placeholder="SKU">
                                <template x-for="(val, name) in v.attrs" :key="name">
                                    <input type="hidden" :name="`variants[${i}][attrs][${name}]`" :value="val">
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="!variants.length">
                    <p class="text-sm text-ink-700/50">Add at least one option with values to generate variations.</p>
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
            @php
                $allProductsJs = $allProducts->map(fn($p)=>['id'=>$p->id,'name'=>$p->name])->values();
                $selUp = collect(old('upsell_ids', $product->upsell_ids ?? []))->map(fn($i)=>(int)$i)->values();
                $selCross = collect(old('cross_sell_ids', $product->cross_sell_ids ?? []))->map(fn($i)=>(int)$i)->values();
            @endphp
            <div class="card p-6 space-y-4">
                <div><h2 class="font-semibold">Related products</h2><p class="text-xs text-ink-700/60">Search and add products to recommend. Drives bigger orders.</p></div>
                <div class="grid sm:grid-cols-2 gap-6">
                    @foreach([['upsell_ids','“You may also like” (upsells)',$selUp],['cross_sell_ids','“Frequently bought together” (cross-sells)',$selCross]] as [$field,$label,$sel])
                        <div x-data="relatedPicker({{ Js::from($allProductsJs) }}, {{ Js::from($sel) }}, '{{ $field }}')" @click.outside="open=false">
                            <label class="label">{{ $label }}</label>
                            <div class="relative">
                                <input x-model="q" @focus="open=true" @input="open=true" placeholder="Search products…" class="input py-2" autocomplete="off">
                                <div x-show="open && results.length" x-cloak class="absolute z-20 mt-1 w-full max-h-56 overflow-y-auto rounded-lg border border-ink-100 bg-white shadow-lg">
                                    <template x-for="p in results" :key="p.id">
                                        <button type="button" @click="add(p.id)" class="block w-full text-left px-3 py-2 text-sm hover:bg-gold-50" x-text="p.name"></button>
                                    </template>
                                </div>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <template x-for="p in chosen" :key="p.id">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-gold-100 text-gold-800 text-xs px-2 py-1">
                                        <span x-text="p.name"></span>
                                        <button type="button" @click="remove(p.id)" class="text-gold-700 hover:text-red-600">×</button>
                                        <input type="hidden" :name="field + '[]'" :value="p.id">
                                    </span>
                                </template>
                                <template x-if="!chosen.length"><span class="text-xs text-ink-700/40">None selected.</span></template>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- SEO -->
            <div class="card p-6 space-y-4"
                 x-data="{
                    mt: '{{ addslashes(old('meta_title', $product->meta_title)) }}',
                    md: '{{ addslashes(old('meta_description', $product->meta_description)) }}',
                    slug: '{{ addslashes(old('slug', $product->slug)) }}',
                    name: @js(old('name', $product->name)),
                    get title(){ return this.mt || this.name || 'Product title'; }
                 }">
                <div><h2 class="font-semibold">SEO (Google &amp; search)</h2><p class="text-xs text-ink-700/60">Control how this product appears in Google search results.</p></div>

                {{-- Google preview --}}
                <div class="rounded-lg border border-ink-100 p-3 bg-white">
                    <p class="text-[#1a0dab] text-base leading-tight truncate" x-text="title"></p>
                    <p class="text-[#006621] text-xs">{{ config('app.url') }}/product/<span x-text="slug || 'product-name'"></span></p>
                    <p class="text-ink-700/70 text-sm line-clamp-2" x-text="md || '{{ addslashes(Str::limit(strip_tags($product->short_description ?? ''),150)) }}' || 'Your meta description preview appears here.'"></p>
                </div>

                <div>
                    <label class="label">URL slug</label>
                    <input name="slug" x-model="slug" class="input" placeholder="auto-generated from name">
                </div>
                <div>
                    <label class="label flex justify-between">Meta title <span class="text-xs text-ink-700/40" x-text="mt.length + '/60'"></span></label>
                    <input name="meta_title" x-model="mt" maxlength="60" class="input" placeholder="Defaults to product name">
                </div>
                <div>
                    <label class="label flex justify-between">Meta description <span class="text-xs text-ink-700/40" x-text="md.length + '/160'"></span></label>
                    <textarea name="meta_description" x-model="md" maxlength="160" rows="2" class="input" placeholder="A short, enticing summary for search results"></textarea>
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
                <div>
                    <label class="label">Tags</label>
                    <input name="tags" value="{{ old('tags', $product->tags) }}" class="input" placeholder="bestseller, new-arrival, eid">
                    <p class="text-xs text-ink-700/50 mt-1">Comma-separated. Used to filter products in the admin list &amp; on the shop.</p>
                </div>
                <div class="border-t border-ink-100 pt-4" x-data="{ rows: @js(array_values(old('custom_fields', $product->custom_fields ?? []))) }">
                    <label class="label">Custom fields (any purpose)</label>

                    {{-- Primary custom field (kept for back-compat) --}}
                    <div class="grid grid-cols-2 gap-2">
                        <input name="custom_label" value="{{ old('custom_label', $product->custom_label) }}" class="input" placeholder="Label (e.g. Material)">
                        <input name="custom_value" value="{{ old('custom_value', $product->custom_value) }}" class="input" placeholder="Value (e.g. 22k Gold)">
                    </div>
                    <label class="flex items-center gap-2 text-sm mt-2"><input type="checkbox" name="custom_show" value="1" @checked(old('custom_show', $product->custom_show))> Show on product page</label>

                    {{-- Additional custom fields (unlimited) --}}
                    <template x-for="(row, i) in rows" :key="i">
                        <div class="mt-3 rounded-md border border-ink-100 p-3">
                            <div class="grid grid-cols-2 gap-2">
                                <input :name="`custom_fields[${i}][label]`" x-model="row.label" class="input" placeholder="Label">
                                <div class="flex gap-2">
                                    <input :name="`custom_fields[${i}][value]`" x-model="row.value" class="input flex-1" placeholder="Value">
                                    <button type="button" @click="rows.splice(i, 1)" class="text-red-500 px-2 text-xl leading-none" title="Remove">&times;</button>
                                </div>
                            </div>
                            <label class="flex items-center gap-2 text-sm mt-2">
                                <input type="checkbox" :name="`custom_fields[${i}][show]`" value="1" x-model="row.show"> Show on product page
                            </label>
                        </div>
                    </template>

                    <button type="button" @click="rows.push({label:'', value:'', show:false})" class="btn-outline mt-3 text-sm">+ Add another custom field</button>
                    <p class="text-xs text-ink-700/50 mt-2">Use for anything (material, purity, weight…). Shown fields appear on the product page; all are filterable in the admin list.</p>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $product->is_featured))> Featured on homepage</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_bestseller" value="1" @checked(old('is_bestseller', $product->is_bestseller))> Mark as best seller</label>
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
                                {{-- Buttons target standalone forms (below) via form="" so they are NOT nested in the product form --}}
                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-1 rounded-lg">
                                    @unless($image->is_primary)
                                        <button type="submit" form="img-primary-{{ $image->id }}" class="text-[10px] bg-white rounded px-1.5 py-0.5">Primary</button>
                                    @endunless
                                    <button type="submit" form="img-del-{{ $image->id }}" class="text-[10px] bg-red-600 text-white rounded px-1.5 py-0.5">Del</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div id="imgOrderInputs"></div>
                @endif
                <input type="file" name="images[]" multiple accept="image/*" class="input text-sm">
                <p class="text-xs text-ink-700/50 mt-2">Upload JPG/PNG/WebP. First uploaded becomes primary if none set.</p>

                {{-- Gallery videos (YouTube/Vimeo link or uploaded MP4) --}}
                <div class="border-t border-ink-100 mt-4 pt-4" x-data="{ vids: @js(array_values(old('video_urls', $product->video_urls ?? []))) }">
                    <label class="label">Gallery videos</label>
                    <p class="text-xs text-ink-700/50 mb-2">Paste a YouTube/Vimeo link, or upload an MP4 below. Videos show inside the product image gallery.</p>
                    <template x-for="(v, i) in vids" :key="i">
                        <div class="flex gap-2 mb-2">
                            <input :name="`video_urls[${i}]`" x-model="vids[i]" class="input flex-1" placeholder="https://youtu.be/…">
                            <button type="button" @click="vids.splice(i, 1)" class="text-red-500 px-2 text-xl leading-none" title="Remove">&times;</button>
                        </div>
                    </template>
                    <button type="button" @click="vids.push('')" class="btn-outline text-sm">+ Add video link</button>
                    <div class="mt-3">
                        <label class="label text-xs">Or upload MP4 / WebM (max 30 MB each)</label>
                        <input type="file" name="video_files[]" multiple accept="video/mp4,video/webm,video/quicktime" class="input text-sm">
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

{{-- Standalone image-action forms (kept OUTSIDE the product form to avoid nested-form bugs) --}}
@if($product->exists && $product->images->isNotEmpty())
    @foreach($product->images as $image)
        @unless($image->is_primary)
            <form id="img-primary-{{ $image->id }}" action="{{ route('admin.products.images.primary', $image) }}" method="POST" class="hidden">@csrf</form>
        @endunless
        <form id="img-del-{{ $image->id }}" action="{{ route('admin.products.images.delete', $image) }}" method="POST" class="hidden" onsubmit="return confirm('Delete this image?')">@csrf @method('DELETE')</form>
    @endforeach
@endif

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
