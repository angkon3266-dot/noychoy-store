@extends('layouts.admin')
@section('title', 'Menu')
@section('heading', 'Navigation menu')

@section('content')
<form action="{{ route('admin.menu.update') }}" method="POST"
      x-data="menuBuilder({{ Js::from($items) }}, {{ Js::from($categories) }})"
      class="max-w-4xl space-y-6">
    @csrf
    <input type="hidden" name="menu_json" :value="JSON.stringify(items)">

    {{-- Builder --}}
    <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="font-semibold">Menu items</h2>
                <p class="text-xs text-ink-700/60">Build your header navigation. Top-level items can have a dropdown of sub-items.</p>
            </div>
            <div class="flex gap-2">
                <button type="button" @click="addItem('category')" class="btn-outline py-1.5">+ Category</button>
                <button type="button" @click="addItem('link')" class="btn-outline py-1.5">+ Custom link</button>
            </div>
        </div>

        <template x-if="!items.length">
            <p class="text-sm text-ink-700/50 py-6 text-center">No items yet. Add a category or custom link to begin.</p>
        </template>

        <div class="space-y-3">
            <template x-for="(item, i) in items" :key="i">
                <div class="rounded-lg border border-ink-100 bg-ink-50/40 p-3">
                    {{-- top-level row --}}
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="flex flex-col gap-0.5">
                            <button type="button" @click="move(items, i, -1)" class="text-ink-700/50 hover:text-ink-900 leading-none" :disabled="i===0">▲</button>
                            <button type="button" @click="move(items, i, 1)" class="text-ink-700/50 hover:text-ink-900 leading-none" :disabled="i===items.length-1">▼</button>
                        </div>
                        <input x-model="item.label" placeholder="Label" class="input py-2 w-40">
                        <template x-if="item.type === 'category'">
                            <select x-model.number="item.value" class="input py-2 flex-1 min-w-[10rem]">
                                <option value="">— choose category —</option>
                                <template x-for="c in categories" :key="c.id"><option :value="c.id" x-text="(c.parent_id ? '— ' : '') + c.name"></option></template>
                            </select>
                        </template>
                        <template x-if="item.type === 'link'">
                            <input x-model="item.value" placeholder="/shop or https://…" class="input py-2 flex-1 min-w-[10rem]">
                        </template>
                        <label class="flex items-center gap-1 text-xs text-ink-700/70"><input type="checkbox" x-model="item.new_tab"> new tab</label>
                        <span class="text-[11px] uppercase tracking-wide px-2 py-1 rounded bg-white border border-ink-100" x-text="item.type"></span>
                        <button type="button" @click="items.splice(i,1)" class="text-red-600 text-lg leading-none">×</button>
                    </div>

                    {{-- children --}}
                    <div class="mt-3 ml-8 space-y-2">
                        <template x-for="(child, j) in item.children" :key="j">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-ink-300">↳</span>
                                <div class="flex flex-col gap-0.5">
                                    <button type="button" @click="move(item.children, j, -1)" class="text-ink-700/40 hover:text-ink-900 leading-none text-xs" :disabled="j===0">▲</button>
                                    <button type="button" @click="move(item.children, j, 1)" class="text-ink-700/40 hover:text-ink-900 leading-none text-xs" :disabled="j===item.children.length-1">▼</button>
                                </div>
                                <input x-model="child.label" placeholder="Label" class="input py-1.5 w-36">
                                <template x-if="child.type === 'category'">
                                    <select x-model.number="child.value" class="input py-1.5 flex-1 min-w-[9rem]">
                                        <option value="">— category —</option>
                                        <template x-for="c in categories" :key="c.id"><option :value="c.id" x-text="(c.parent_id ? '— ' : '') + c.name"></option></template>
                                    </select>
                                </template>
                                <template x-if="child.type === 'link'">
                                    <input x-model="child.value" placeholder="/shop or https://…" class="input py-1.5 flex-1 min-w-[9rem]">
                                </template>
                                <label class="flex items-center gap-1 text-xs text-ink-700/70"><input type="checkbox" x-model="child.new_tab"> new tab</label>
                                <button type="button" @click="item.children.splice(j,1)" class="text-red-600 leading-none">×</button>
                            </div>
                        </template>
                        <div class="flex gap-2">
                            <button type="button" @click="item.children.push({label:'',type:'category',value:'',new_tab:false,children:[]})" class="text-xs text-gold-700 hover:underline">+ sub-category</button>
                            <button type="button" @click="item.children.push({label:'',type:'link',value:'',new_tab:false,children:[]})" class="text-xs text-gold-700 hover:underline">+ sub-link</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- Behaviour --}}
    <div class="card p-6 space-y-4">
        <h2 class="font-semibold">Menu behaviour</h2>
        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="label">Desktop dropdowns open on</label>
                <select name="menu_desktop_trigger" class="input">
                    <option value="hover" @selected(($theme['menu_desktop_trigger'] ?? 'hover')=='hover')>Hover (open when pointer is over)</option>
                    <option value="click" @selected(($theme['menu_desktop_trigger'] ?? 'hover')=='click')>Click (open on click/tap)</option>
                </select>
                <p class="text-xs text-ink-700/50 mt-1">On mobile the menu always opens as a slide-in drawer with tap-to-expand sub-items.</p>
            </div>
            <div class="flex items-center pt-7">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="menu_show_search" value="1" @checked($theme['menu_show_search'] ?? true)> Show search box in header</label>
            </div>
            <div><label class="label">Highlight button label (optional)</label><input name="menu_cta_label" value="{{ $theme['menu_cta_label'] ?? '' }}" class="input" placeholder="e.g. Sale"></div>
            <div><label class="label">Highlight button link</label><input name="menu_cta_link" value="{{ $theme['menu_cta_link'] ?? '' }}" class="input" placeholder="/shop?sort=price_asc"></div>
        </div>
    </div>

    <div class="flex justify-end"><button class="btn-primary">Save menu</button></div>
</form>

<script>
function menuBuilder(items, categories) {
    return {
        items: items || [],
        categories: categories || [],
        addItem(type) {
            this.items.push({ label: type === 'link' ? '' : '', type, value: '', new_tab: false, children: [] });
        },
        move(arr, i, dir) {
            const n = i + dir;
            if (n < 0 || n >= arr.length) return;
            const [el] = arr.splice(i, 1);
            arr.splice(n, 0, el);
        },
    };
}
</script>
@endsection
