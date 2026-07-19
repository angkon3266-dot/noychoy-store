@extends('layouts.admin')
@section('title', 'Products')
@section('heading', 'Products')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <form method="GET" class="flex flex-wrap gap-2">
        <input name="q" value="{{ request('q') }}" placeholder="Search products…" class="input py-2 w-48">
        <select name="status" onchange="this.form.submit()" class="input py-2">
            <option value="">All status</option>
            <option value="published" @selected(request('status')=='published')>Published</option>
            <option value="draft" @selected(request('status')=='draft')>Draft</option>
        </select>
        <select name="type" onchange="this.form.submit()" class="input py-2">
            <option value="">All types</option>
            <option value="simple" @selected(request('type')=='simple')>Simple</option>
            <option value="variable" @selected(request('type')=='variable')>Variable</option>
        </select>
        <select name="tag" onchange="this.form.submit()" class="input py-2">
            <option value="">All tags</option>
            @foreach($allTags as $tag)
                <option value="{{ $tag }}" @selected(request('tag')==$tag)>{{ $tag }}</option>
            @endforeach
        </select>
        <select name="category" onchange="this.form.submit()" class="input py-2" title="Filter by category">
            <option value="">All categories</option>
            @foreach($bulkCategories as $cat)
                <option value="{{ $cat->id }}" @selected((string) request('category') === (string) $cat->id)>{{ $cat->name }}</option>
            @endforeach
        </select>
        <input name="custom" value="{{ request('custom') }}" placeholder="Custom field value…" class="input py-2 w-40">
        <select name="per_page" onchange="this.form.submit()" class="input py-2" title="Products per page">
            @foreach(['20','50','100','200','all'] as $pp)
                <option value="{{ $pp }}" @selected((string) request('per_page', '20') === $pp)>{{ $pp === 'all' ? 'Show all' : $pp.' / page' }}</option>
            @endforeach
        </select>
        <button class="btn-outline">Filter</button>
    </form>
    <div class="flex gap-2">
        <button form="serialForm" class="btn-outline" title="Save the ID numbers typed in the list below">Save IDs</button>
        <a href="{{ route('admin.products.import') }}" class="btn-outline">Import CSV</a>
        <a href="{{ route('admin.products.create') }}" class="btn-primary">+ Add product</a>
    </div>
</div>

{{-- Row ID inputs below attach to this form via form="serialForm", so many
     products' IDs can be typed and saved in one go. --}}
<form id="serialForm" action="{{ route('admin.products.bulk-serials') }}" method="POST">@csrf</form>

@php $pageIds = $products->pluck('id')->values(); @endphp
<div x-data="{
        sel: [],
        catIds: [],
        allIds: {{ Js::from($pageIds) }},
        toggleAll(e) { this.sel = e.target.checked ? [...this.allIds] : []; },
        run(action) {
            if (!this.sel.length) return;
            if (action === 'delete' && !confirm('Delete ' + this.sel.length + ' product(s)? This cannot be undone.')) return;
            if (action === 'category' && !this.catIds.length) { alert('Pick at least one category first.'); return; }
            if (action === 'category' && !confirm('This will REPLACE the categories of ' + this.sel.length + ' product(s) with the ' + this.catIds.length + ' selected. Continue?')) return;
            if (action === 'generate_serials' && !confirm('Renumber the ' + this.sel.length + ' selected product(s) as ID 1–' + this.sel.length + ', oldest first? Their current IDs will be replaced.')) return;
            this.$refs.act.value = action;
            this.$refs.bulk.submit();
        }
     }">

    {{-- Bulk action bar --}}
    <div x-show="sel.length" x-cloak class="flex flex-wrap items-center gap-2 mb-3 rounded-lg bg-gold-50 border border-gold-200 px-4 py-2.5 text-sm">
        <span class="font-medium"><span x-text="sel.length"></span> selected</span>
        <span class="text-ink-300">|</span>
        <button type="button" @click="run('publish')" class="text-gold-700 hover:underline">Publish</button>
        <button type="button" @click="run('draft')" class="text-gold-700 hover:underline">Set draft</button>
        <button type="button" @click="run('feature')" class="text-gold-700 hover:underline">Feature on home</button>
        <button type="button" @click="run('unfeature')" class="text-gold-700 hover:underline">Unfeature</button>
        <button type="button" @click="run('bestseller')" class="text-gold-700 hover:underline">Mark bestseller</button>
        <button type="button" @click="run('unbestseller')" class="text-gold-700 hover:underline">Unmark bestseller</button>
        <button type="button" @click="run('generate_serials')" class="text-gold-700 hover:underline" title="Assign ID 1, 2, 3… to the selected products, oldest first">Generate IDs 1–N</button>
        <span class="text-ink-300">|</span>
        <select x-model="catIds" multiple size="1" class="input py-1 text-xs w-44 h-auto max-h-24" title="Ctrl/Cmd-click to pick several">
            @foreach($bulkCategories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
        </select>
        <button type="button" @click="run('category')" class="text-gold-700 hover:underline">Set categories (<span x-text="catIds.length"></span>)</button>
        <span class="text-[11px] text-ink-700/40">replaces existing · Ctrl/Cmd-click for several</span>
        <button type="button" @click="run('delete')" class="text-red-600 hover:underline ml-auto">Delete</button>
    </div>

    {{-- Hidden bulk form --}}
    <form x-ref="bulk" action="{{ route('admin.products.bulk') }}" method="POST" class="hidden">
        @csrf
        <input type="hidden" name="action" x-ref="act">
        <template x-for="cid in catIds" :key="cid"><input type="hidden" name="category_ids[]" :value="cid"></template>
        <template x-for="id in sel" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
    </form>

    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr>
                    <th class="px-4 py-3 w-8"><input type="checkbox" @change="toggleAll($event)" :checked="sel.length && sel.length === allIds.length"></th>
                    <th class="px-4 py-3">Product</th>
                    <th class="px-4 py-3">Category</th>
                    <th class="px-4 py-3">Price &amp; stock</th>
                    <th class="px-4 py-3">Margin</th>
                    <th class="px-4 py-3">Status</th>
                    <th></th>
                </tr>
            </thead>
            @forelse($products as $product)
            <tbody x-data="{ q: false }" class="border-b border-ink-100">
                <tr class="hover:bg-ink-50">
                    <td class="px-4 py-3"><input type="checkbox" value="{{ $product->id }}" x-model.number="sel"></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded bg-gold-100 overflow-hidden shrink-0">
                                @if($product->thumbnail)<img src="{{ $product->thumbnail }}" class="w-full h-full object-cover" alt="">@endif
                            </div>
                            <div>
                                <div class="font-medium">{{ $product->name }} @if($product->is_featured)<span class="badge bg-gold-100 text-gold-700 text-[10px]">★</span>@endif</div>
                                <div class="text-xs text-ink-700/50 flex items-center gap-1.5 flex-wrap">
                                    <span class="inline-flex items-center gap-0.5 text-[10px] text-ink-600">ID #<input form="serialForm" name="serials[{{ $product->id }}]" type="number" min="1" value="{{ $product->serial }}" class="input py-0.5 px-1 w-14 text-[11px]" title="Product ID — edit and press “Save IDs” above"></span>
                                    <span class="badge {{ $product->has_variants ? 'bg-violet-100 text-violet-700' : 'bg-ink-100 text-ink-600' }} text-[10px]">{{ $product->type_label }}</span>
                                    @if($product->sku)<span>{{ $product->sku }}</span>@endif
                                    @foreach($product->tag_list as $t)<span class="badge bg-gold-50 text-gold-700 text-[10px]">{{ $t }}</span>@endforeach
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-ink-700/70">
                        @php $rowCats = $product->categories->isNotEmpty() ? $product->categories->pluck('name') : collect([$product->category?->name])->filter(); @endphp
                        {{ $rowCats->isNotEmpty() ? $rowCats->implode(', ') : '—' }}
                    </td>
                    <td class="px-4 py-3">
                        <form action="{{ route('admin.products.quick', $product) }}" method="POST" class="flex items-center gap-1.5">
                            @csrf @method('PATCH')
                            <span class="text-ink-700/50 text-xs">৳</span>
                            <input name="price" type="number" step="0.01" value="{{ $product->price }}" class="input py-1 w-20 text-xs" title="Price">
                            <input name="stock_quantity" type="number" value="{{ $product->stock_quantity }}" class="input py-1 w-16 text-xs" title="Stock" @disabled(!$product->manage_stock) placeholder="∞">
                            <button class="text-xs text-gold-700 hover:underline">Save</button>
                        </form>
                    </td>
                    <td class="px-4 py-3">
                        @if($product->margin_percent !== null)
                            <span class="font-medium {{ $product->margin_amount < 0 ? 'text-red-600' : ($product->margin_percent < 20 ? 'text-amber-600' : 'text-green-700') }}">{{ $product->margin_percent }}%</span>
                            <div class="text-xs text-ink-700/50">{{ money($product->margin_amount) }}/unit</div>
                        @else
                            <span class="text-xs text-ink-700/40">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3"><span class="badge {{ $product->status=='published' ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }} capitalize">{{ $product->status }}</span></td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <button type="button" @click="q=!q" class="text-gold-700 hover:underline" x-text="q ? 'Close' : 'Quick edit'"></button>
                        <a href="{{ route('admin.products.edit', $product) }}" class="text-ink-700/70 hover:underline ml-2">Edit</a>
                        <form action="{{ route('admin.products.duplicate', $product) }}" method="POST" class="inline">@csrf<button class="text-ink-700/70 hover:underline ml-2">Duplicate</button></form>
                        <form action="{{ route('admin.products.destroy', $product) }}" method="POST" class="inline" onsubmit="return confirm('Delete this product?')">
                            @csrf @method('DELETE')<button class="text-red-600 hover:underline ml-2">Delete</button>
                        </form>
                    </td>
                </tr>
                {{-- Inline quick-edit: price, stock, add images & video links without opening the full editor --}}
                <tr x-show="q" x-cloak>
                    <td colspan="7" class="bg-ink-50/60 px-4 py-4">
                        <form action="{{ route('admin.products.quick-media', $product) }}" method="POST" enctype="multipart/form-data" class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 items-start">
                            @csrf
                            <div><label class="label">Selling price (৳)</label><input name="price" type="number" step="0.01" value="{{ $product->price }}" class="input"></div>
                            <div><label class="label">Stock</label><input name="stock_quantity" type="number" value="{{ $product->stock_quantity }}" class="input" @disabled(!$product->manage_stock) placeholder="{{ $product->manage_stock ? '' : 'Not tracked' }}"></div>
                            <div><label class="label">Product cost (৳)</label><input name="cost_price" type="number" step="0.01" value="{{ $product->cost_price }}" class="input" placeholder="Supplier cost"></div>
                            <div><label class="label">Transport / packaging (৳)</label><input name="transport_cost" type="number" step="0.01" value="{{ $product->transport_cost }}" class="input" placeholder="Per unit"></div>
                            <div class="lg:col-span-4"><label class="label">Meta description <span class="text-ink-700/40 font-normal">(SEO)</span></label><textarea name="meta_description" rows="2" class="input" placeholder="Short summary shown in Google results">{{ $product->meta_description }}</textarea></div>
                            <div class="lg:col-span-2" x-data="{ picks: [] }">
                                <label class="label">Add images <span class="text-ink-700/40 font-normal">({{ $product->images()->count() }} now)</span></label>
                                <input type="file" name="images[]" accept="image/*" multiple class="input text-sm">
                                <button type="button" @click="$store.mediaLib.openWith(u => { if(!picks.includes(u)) picks.push(u) }, 'products')" class="btn-outline text-xs py-1 mt-1">+ From library</button>
                                <div class="flex flex-wrap gap-1 mt-1" x-show="picks.length" x-cloak>
                                    <template x-for="(u, i) in picks" :key="i">
                                        <div class="relative">
                                            <img :src="u" class="w-10 h-10 object-cover rounded" alt="">
                                            <button type="button" @click="picks.splice(i, 1)" class="absolute -top-1 -right-1 bg-red-600 text-white rounded-full w-4 h-4 text-[10px] leading-none">&times;</button>
                                            <input type="hidden" name="image_urls[]" :value="u">
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <div class="lg:col-span-2"><label class="label">Add video link</label><input name="video_urls[]" class="input" placeholder="YouTube link or .mp4 URL"></div>
                            <div class="lg:col-span-2"><label class="label">Upload video <span class="text-ink-700/40 font-normal">(MP4/WebM/MOV, max {{ upload_limit_mb() }} MB)</span></label><input type="file" name="video_files[]" accept="video/mp4,video/webm,video/quicktime,video/x-m4v" multiple class="input text-sm"></div>
                            <div><label class="label">Tags</label><input name="tags" value="{{ $product->tags }}" class="input" placeholder="bestseller, eid"></div>
                            <div><label class="label">Colours</label><input name="colors" value="{{ implode(', ', $product->color_list ?? []) }}" class="input" placeholder="Gold, Silver"></div>
                            <div class="lg:col-span-2 flex items-center gap-3">
                                <button class="btn-primary">Save</button>
                                <a href="{{ route('admin.products.edit', $product) }}" class="text-xs text-ink-700/60 hover:underline">Open full editor (related products, variants, SEO, reorder…)</a>
                            </div>
                        </form>
                    </td>
                </tr>
            </tbody>
            @empty
            <tbody><tr><td colspan="7" class="px-4 py-10 text-center text-ink-700/50">No products yet. <a href="{{ route('admin.products.create') }}" class="text-gold-700 hover:underline">Add your first product</a>.</td></tr></tbody>
            @endforelse
        </table>
    </div>
</div>
<div class="mt-6">{{ $products->links() }}</div>
@endsection
