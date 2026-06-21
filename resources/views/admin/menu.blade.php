@extends('layouts.admin')
@section('title', 'Menu')
@section('heading', 'Navigation menu')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif

<form action="{{ route('admin.menu.update') }}" method="POST" x-data="menuBuilder({{ Js::from($items) }})">
    @csrf
    <input type="hidden" name="menu_json" :value="json">

    <div class="grid lg:grid-cols-3 gap-6">
        {{-- Menu items --}}
        <div class="lg:col-span-2 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Menu items</h2>
                <button type="button" @click="add()" class="btn-outline py-1.5">+ Add item</button>
            </div>

            <template x-for="(item, i) in items" :key="i">
                <div class="card overflow-hidden">
                    {{-- Row header --}}
                    <div class="flex items-center gap-2 px-4 py-3 bg-ink-50">
                        <div class="flex flex-col text-ink-700/40">
                            <button type="button" @click="move(i,-1)" class="hover:text-ink-700 leading-none">▲</button>
                            <button type="button" @click="move(i,1)" class="hover:text-ink-700 leading-none">▼</button>
                        </div>
                        <button type="button" @click="toggle(i)" class="flex-1 text-left font-medium flex items-center gap-2">
                            <span x-text="item.label || '(no label)'"></span>
                            <span class="badge bg-ink-100 text-ink-600 text-[10px] capitalize" x-text="item.type"></span>
                            <span x-show="item.badge" class="badge bg-gold-100 text-gold-700 text-[10px]" x-text="item.badge"></span>
                        </button>
                        <button type="button" @click="toggle(i)" class="text-xs text-gold-700 hover:underline" x-text="open===i ? 'Close' : 'Edit'"></button>
                        <button type="button" @click="remove(i)" class="text-red-600 text-lg leading-none">×</button>
                    </div>

                    {{-- Editor --}}
                    <div x-show="open===i" x-cloak class="p-4 space-y-4">
                        <div class="grid sm:grid-cols-2 gap-3">
                            <div><label class="label">Label *</label><input x-model="item.label" class="input"></div>
                            <div><label class="label">URL</label><input x-model="item.url" class="input" placeholder="/shop or https://…"></div>
                        </div>

                        <div>
                            <label class="label">Menu type</label>
                            <div class="flex gap-2">
                                <template x-for="t in ['link','dropdown','mega']" :key="t">
                                    <button type="button" @click="item.type=t"
                                        class="px-3 py-1.5 rounded-md border text-sm capitalize"
                                        :class="item.type===t ? 'border-gold-500 bg-gold-50 text-gold-800' : 'border-ink-100'"
                                        x-text="t==='mega' ? 'Mega menu' : t"></button>
                                </template>
                            </div>
                        </div>

                        <div class="grid sm:grid-cols-2 gap-3">
                            <div><label class="label">Badge (optional)</label><input x-model="item.badge" class="input" placeholder="New / Sale"></div>
                            <div class="flex items-end gap-4">
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="item.new_tab"> New tab</label>
                                <label class="flex items-center gap-2 text-sm" x-show="item.type!=='link'"><input type="checkbox" x-model="item.view_all_mobile"> “View All” on mobile</label>
                            </div>
                        </div>

                        {{-- Dropdown children --}}
                        <div x-show="item.type==='dropdown'" class="space-y-2">
                            <label class="label">Dropdown links</label>
                            <template x-for="(c, j) in item.children" :key="j">
                                <div class="flex gap-2 items-center">
                                    <input x-model="c.label" class="input py-1.5" placeholder="Label">
                                    <input x-model="c.url" class="input py-1.5" placeholder="URL">
                                    <label class="text-xs flex items-center gap-1"><input type="checkbox" x-model="c.new_tab"> ↗</label>
                                    <button type="button" @click="removeChild(item,j)" class="text-red-600">×</button>
                                </div>
                            </template>
                            <button type="button" @click="addChild(item)" class="text-sm text-gold-700 hover:underline">+ Add link</button>
                        </div>

                        {{-- Mega columns --}}
                        <div x-show="item.type==='mega'" class="space-y-3">
                            <div class="flex items-center justify-between">
                                <label class="label mb-0">Columns</label>
                                <button type="button" @click="addColumn(item)" class="text-sm text-gold-700 hover:underline">+ Add column</button>
                            </div>
                            <template x-for="(col, k) in item.columns" :key="k">
                                <div class="rounded-lg border border-ink-100 p-3 space-y-2">
                                    <div class="flex gap-2 items-center">
                                        <input x-model="col.heading" class="input py-1.5 font-medium" placeholder="Column heading (e.g. Rings)">
                                        <button type="button" @click="removeColumn(item,k)" class="text-red-600">×</button>
                                    </div>
                                    <template x-for="(l, m) in col.links" :key="m">
                                        <div class="flex gap-2 items-center pl-3">
                                            <input x-model="l.label" class="input py-1.5" placeholder="Link label">
                                            <input x-model="l.url" class="input py-1.5" placeholder="URL">
                                            <button type="button" @click="removeLink(col,m)" class="text-red-600">×</button>
                                        </div>
                                    </template>
                                    <button type="button" @click="addLink(col)" class="text-xs text-gold-700 hover:underline pl-3">+ Add link</button>
                                </div>
                            </template>
                            <template x-if="!item.columns.length"><p class="text-sm text-ink-700/50">No columns yet — add one.</p></template>
                        </div>
                    </div>
                </div>
            </template>

            <template x-if="!items.length"><div class="card p-8 text-center text-ink-700/50">No menu items. Click “Add item”.</div></template>
        </div>

        {{-- Behaviour + save --}}
        <div class="space-y-6">
            <div class="card p-6 space-y-4">
                <h2 class="font-semibold">Behaviour</h2>
                <div>
                    <label class="label">Desktop dropdowns open on</label>
                    <select name="menu_desktop_trigger" class="input">
                        <option value="hover" @selected(($theme['menu_desktop_trigger'] ?? 'hover')==='hover')>Hover</option>
                        <option value="click" @selected(($theme['menu_desktop_trigger'] ?? 'hover')==='click')>Click</option>
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="menu_show_search" value="1" @checked($theme['menu_show_search'] ?? true)> Show search box in header</label>
                <div><label class="label">CTA button label (optional)</label><input name="menu_cta_label" value="{{ $theme['menu_cta_label'] ?? '' }}" class="input" placeholder="Track order"></div>
                <div><label class="label">CTA button link</label><input name="menu_cta_link" value="{{ $theme['menu_cta_link'] ?? '' }}" class="input" placeholder="/track"></div>
            </div>
            <div class="card p-6">
                <button class="btn-primary w-full">Save menu</button>
                <p class="text-xs text-ink-700/50 mt-2">Tip: use <strong>Mega menu</strong> for big categories (Womens) with columns of links; <strong>Dropdown</strong> for a simple list; <strong>Link</strong> for a single page. On mobile, dropdown/mega items expand as an accordion.</p>
            </div>
        </div>
    </div>
</form>
@endsection
