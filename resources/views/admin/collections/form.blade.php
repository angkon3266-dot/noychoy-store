@extends('layouts.admin')
@section('title', $collection->exists ? 'Edit collection' : 'New collection')
@section('heading', $collection->exists ? 'Edit collection' : 'New collection')

@section('content')
@if($errors->any())<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm">{{ $errors->first() }}</div>@endif

<form method="POST"
      action="{{ $collection->exists ? route('admin.collections.update', $collection) : route('admin.collections.store') }}"
      enctype="multipart/form-data"
      class="grid lg:grid-cols-3 gap-6 max-w-6xl pb-24"
      x-data="collectionBuilder({
          rules: {{ Js::from(old('rules', $collection->rules ?? [])) }},
          fields: {{ Js::from($fields) }},
          operatorLabels: {{ Js::from($operatorLabels) }},
          match: '{{ old('match', $collection->match ?? 'all') }}',
          type: '{{ old('type', $collection->type ?? 'smart') }}',
          count: {{ $matchCount }},
          previewUrl: '{{ route('admin.collections.preview') }}'
      })">
    @csrf
    @if($collection->exists) @method('PUT') @endif

    <div class="lg:col-span-2 space-y-6">
        <div class="card p-6 space-y-4">
            <div>
                <label class="label">Name</label>
                <input name="name" value="{{ old('name', $collection->name) }}" class="input" placeholder="Gifts under 2,000" required>
                @if($collection->exists)
                    <p class="text-xs text-ink-700/50 mt-1">Lives at <a href="{{ route('collection.show', $collection->slug) }}" target="_blank" rel="noopener" class="text-gold-700 underline">/collection/{{ $collection->slug }}</a>. Renaming keeps the address, so shared links never break.</p>
                @endif
            </div>
            <div>
                <label class="label">Description</label>
                <textarea name="description" rows="2" class="input" placeholder="One line that tells a shopper what this is.">{{ old('description', $collection->description) }}</textarea>
            </div>
            <div>
                <label class="label">Cover image</label>
                <x-media-field name="image" :value="$collection->image" folder="collections" />
            </div>
        </div>

        <div class="card p-6">
            <div class="flex items-center justify-between mb-1">
                <h2 class="font-semibold">Which products</h2>
                <div class="flex text-sm">
                    <button type="button" @click="type='smart'" :class="type==='smart' ? 'bg-gold-600 text-white' : 'bg-ink-100 text-ink-700'" class="rounded-l-md px-3 py-1.5">Automatic</button>
                    <button type="button" @click="type='manual'" :class="type==='manual' ? 'bg-gold-600 text-white' : 'bg-ink-100 text-ink-700'" class="rounded-r-md px-3 py-1.5">Hand-picked</button>
                </div>
            </div>
            <input type="hidden" name="type" :value="type">
            <input type="hidden" name="match" :value="match">

            <div x-show="type==='smart'" class="mt-4">
                <div class="flex items-center gap-2 text-sm mb-3">
                    <span>Products must match</span>
                    <select x-model="match" @change="preview()" class="input py-1.5 w-auto">
                        <option value="all">all of these conditions</option>
                        <option value="any">any of these conditions</option>
                    </select>
                </div>

                <template x-for="(r, i) in rules" :key="i">
                    <div class="flex flex-wrap gap-2 mb-2 items-start">
                        <select :name="`rules[${i}][field]`" x-model="r.field" @change="onFieldChange(r)" class="input py-1.5 w-44">
                            <template x-for="(f, key) in fields" :key="key">
                                <option :value="key" x-text="f.label"></option>
                            </template>
                        </select>

                        <select :name="`rules[${i}][operator]`" x-model="r.operator" @change="preview()" class="input py-1.5 w-52">
                            <template x-for="op in (fields[r.field]?.operators || [])" :key="op">
                                <option :value="op" x-text="operatorLabels[op]"></option>
                            </template>
                        </select>

                        <select x-show="fields[r.field]?.type === 'select'"
                                :name="fields[r.field]?.type === 'select' ? `rules[${i}][value]` : ''"
                                x-model="r.value" @change="preview()" class="input py-1.5 w-44">
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>

                        <input x-show="needsTypedValue(r)"
                               :name="needsTypedValue(r) ? `rules[${i}][value]` : ''"
                               :type="['number','date'].includes(fields[r.field]?.type) ? 'number' : 'text'"
                               x-model="r.value" @input.debounce.400ms="preview()"
                               class="input py-1.5 w-44" :placeholder="placeholderFor(r)">

                        <button type="button" @click="rules.splice(i,1); preview()" class="text-red-500 px-2 text-xl leading-none" title="Remove condition">&times;</button>

                        <p x-show="fields[r.field]?.hint" class="w-full text-xs text-ink-700/50 -mt-1" x-text="fields[r.field]?.hint"></p>
                    </div>
                </template>

                <button type="button" @click="addRule()" class="btn-outline text-sm mt-1">+ Add condition</button>

                <div class="mt-4 rounded-md bg-gold-50 border border-gold-200 px-4 py-3 text-sm">
                    <template x-if="rules.length === 0">
                        <p class="text-ink-700/70"><strong>No conditions yet.</strong> A smart collection with no conditions stays empty on purpose — showing the whole catalogue under a name like "Eid Gifts" looks like it worked when it did not.</p>
                    </template>
                    <template x-if="rules.length > 0">
                        <p>
                            <strong x-text="count"></strong> product<span x-show="count !== 1">s</span> match right now.
                            <span x-show="ignored > 0" class="text-red-600" x-text="`(${ignored} incomplete condition${ignored===1?'':'s'} ignored)`"></span>
                            <span class="text-ink-700/50">This keeps itself up to date as your catalogue changes.</span>
                        </p>
                    </template>
                </div>
            </div>

            <div class="mt-4">
                <details class="rounded-md border border-ink-100 p-3" {{ $pinned ? 'open' : '' }}>
                    <summary class="text-sm font-medium cursor-pointer">
                        <span x-text="type==='manual' ? 'Products in this collection' : 'Pinned products'"></span>
                        ({{ count($pinned) }})
                    </summary>
                    <p class="text-xs text-ink-700/60 mt-2" x-text="type==='manual' ? 'Tick the products that belong here.' : 'These always show, on top of whatever the conditions match — for forcing a hero piece to the front.'"></p>
                    <div class="mt-3 max-h-72 overflow-y-auto space-y-1">
                        @foreach($allProducts as $p)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="product_ids[]" value="{{ $p->id }}" @checked(in_array($p->id, old('product_ids', $pinned)))>
                                <span>{{ $p->name }}</span>
                                @if($p->sku)<span class="text-xs text-ink-700/40">{{ $p->sku }}</span>@endif
                            </label>
                        @endforeach
                    </div>
                </details>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card p-6 space-y-4">
            <h2 class="font-semibold">Visibility</h2>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $collection->is_active ?? true))> Active (visible on the storefront)</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="show_in_menu" value="1" @checked(old('show_in_menu', $collection->show_in_menu ?? false))> Offer in the menu builder</label>
            <p class="text-xs text-ink-700/50 -mt-2">This only makes it <em>available</em> to pick in <a href="{{ route('admin.menu') }}" class="text-gold-700 underline">Menu</a> and on the homepage occasion tiles — it does not add it to the menu for you.</p>
            <div>
                <label class="label">Default sort</label>
                <select name="sort" class="input">
                    @foreach(['new' => 'Newest', 'best_selling' => 'Best selling', 'popular' => 'Most popular', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'name' => 'Name A-Z'] as $k => $label)
                        <option value="{{ $k }}" @selected(old('sort', $collection->sort ?? 'new') === $k)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Position</label>
                <input name="position" type="number" min="0" value="{{ old('position', $collection->position ?? 0) }}" class="input">
            </div>
        </div>

        <div class="card p-6 space-y-4">
            <h2 class="font-semibold">SEO</h2>
            <div><label class="label">Meta title</label><input name="meta_title" value="{{ old('meta_title', $collection->meta_title) }}" class="input"></div>
            <div><label class="label">Meta description</label><textarea name="meta_description" rows="3" class="input">{{ old('meta_description', $collection->meta_description) }}</textarea></div>
        </div>

        <div class="card p-6">
            <button class="btn-primary w-full">{{ $collection->exists ? 'Save collection' : 'Create collection' }}</button>
            <a href="{{ route('admin.collections.index') }}" class="block text-center text-sm text-ink-700/60 mt-3 hover:underline">Cancel</a>
        </div>
    </div>
</form>
@endsection
