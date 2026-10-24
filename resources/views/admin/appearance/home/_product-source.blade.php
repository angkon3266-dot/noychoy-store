{{--
    Where a product section takes its products from. $p is the key prefix
    ("featured", "best", "new"), $autoLabel what "automatic" means for it.
    Read by HomeController::sectionProductIds().
--}}
@php
    $src = $home[$p.'_source'] ?? 'auto';
    $picked = collect($home[$p.'_product_ids'] ?? [])->map(fn ($i) => (int) $i)->values();
@endphp
<div x-data="{ src: @js($src) }" class="rounded-lg border border-ink-100 bg-ink-50/50 p-3 space-y-3">
    <p class="text-sm font-semibold text-ink-800">Which products</p>
    <div class="flex flex-wrap gap-2 text-sm">
        @foreach(['auto' => $autoLabel, 'products' => 'Pick them myself', 'collection' => 'From a collection', 'category' => 'From a category'] as $value => $label)
            <label class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 cursor-pointer transition"
                   :class="src === '{{ $value }}' ? 'border-gold-500 bg-white ring-1 ring-gold-400' : 'border-ink-200 bg-white hover:border-gold-300'">
                <input type="radio" name="home[{{ $p }}_source]" value="{{ $value }}" x-model="src" class="sr-only">
                {{ $label }}
            </label>
        @endforeach
    </div>

    {{-- Hand-picked, in the order picked --}}
    <div x-show="src === 'products'" x-cloak>
        <input type="hidden" name="home_products_present[{{ $p }}]" value="1">
        <div x-data="relatedPicker(@js($homeProducts), @js($picked), 'home_products[{{ $p }}]')" @click.outside="open = false">
            <div class="relative">
                <input x-model="q" @focus="open = true" @input="open = true" placeholder="Search products, then tick the ones to show…" class="input py-2" autocomplete="off">
                <div x-show="open" x-cloak class="absolute z-30 mt-1 w-full rounded-lg border border-ink-100 bg-white shadow-lg">
                    <div class="max-h-64 overflow-y-auto">
                        <template x-for="item in results" :key="item.id">
                            <button type="button" @click="toggle(item.id)" class="flex items-center gap-2 w-full text-left px-3 py-2 text-sm hover:bg-gold-50">
                                <span class="w-4 h-4 rounded border flex items-center justify-center shrink-0" :class="has(item.id) ? 'bg-gold-600 border-gold-600' : 'border-ink-300 bg-white'">
                                    <svg x-show="has(item.id)" class="w-3 h-3 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                </span>
                                <img :src="item.thumb" x-show="item.thumb" class="w-8 h-8 rounded object-cover bg-ink-100 shrink-0" alt="">
                                <span x-text="item.name" :class="has(item.id) && 'font-medium'"></span>
                            </button>
                        </template>
                        <p x-show="!results.length" class="px-3 py-3 text-xs text-ink-700/50">No products match.</p>
                    </div>
                    <div class="flex items-center justify-between border-t border-ink-100 px-3 py-2 bg-ink-50/60 rounded-b-lg">
                        <span class="text-xs text-ink-700/60"><span x-text="selected.length"></span> picked</span>
                        <button type="button" @click="open = false; q = ''" class="text-xs font-semibold text-gold-700 hover:underline">Done</button>
                    </div>
                </div>
            </div>
            <div class="mt-2 flex flex-wrap gap-1.5">
                <template x-for="(item, n) in chosen" :key="item.id">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white border border-ink-100 text-xs pl-1 pr-2 py-1">
                        <span class="text-ink-700/40 tabular-nums w-4 text-center" x-text="n + 1"></span>
                        <img :src="item.thumb" x-show="item.thumb" class="w-5 h-5 rounded-full object-cover shrink-0" alt="">
                        <span x-text="item.name"></span>
                        <button type="button" @click="remove(item.id)" class="text-ink-700/50 hover:text-red-600" title="Take out">×</button>
                        <input type="hidden" :name="field + '[]'" :value="item.id">
                    </span>
                </template>
                <template x-if="!chosen.length"><span class="text-xs text-ink-700/50">None picked yet — they show in the order you pick them.</span></template>
            </div>
        </div>
    </div>

    <div x-show="src === 'collection'" x-cloak>
        <select name="home[{{ $p }}_collection_id]" class="input">
            <option value="">Choose a collection…</option>
            @foreach($homeCollections as $c)
                <option value="{{ $c->id }}" @selected((string) ($home[$p.'_collection_id'] ?? '') === (string) $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        <p class="text-xs text-ink-700/50 mt-1">Newest first. Build or edit collections under <a href="{{ route('admin.collections.index') }}" class="underline">Collections</a>.</p>
    </div>

    <div x-show="src === 'category'" x-cloak>
        <select name="home[{{ $p }}_category_id]" class="input">
            <option value="">Choose a category…</option>
            @foreach($allCategories as $cat)
                <option value="{{ $cat->id }}" @selected((string) ($home[$p.'_category_id'] ?? '') === (string) $cat->id)>{{ $cat->name }}</option>
            @endforeach
        </select>
        <p class="text-xs text-ink-700/50 mt-1">Newest first.</p>
    </div>

    <div class="flex items-center gap-2">
        <label class="text-sm text-ink-700" for="home-{{ $p }}-limit">How many to show</label>
        <input id="home-{{ $p }}-limit" type="number" min="1" max="24" name="home[{{ $p }}_limit]" value="{{ $home[$p.'_limit'] ?? '' }}" class="input w-20 py-1.5" placeholder="{{ config('home.defaults.'.$p.'_limit') }}">
    </div>
</div>
