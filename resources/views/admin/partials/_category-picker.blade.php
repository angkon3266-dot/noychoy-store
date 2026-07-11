{{-- Ordered category picker. Params: $field, $label, $help, $selectedIds, $allCategories.
     Submits $field[] in the chosen order, plus a $field_present marker so an empty
     selection is saved as "none" (rather than being ignored). --}}
<div x-data="catPicker(@js($allCategories->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->values()), @js(collect($selectedIds ?? [])->map(fn($i) => (int) $i)->values()))">
    <label class="label">{{ $label }}</label>
    @if(!empty($help))<p class="text-xs text-ink-700/50 mb-2">{{ $help }}</p>@endif

    <div class="space-y-1.5 mb-2">
        <template x-for="(c, i) in selected" :key="c.id">
            <div class="flex items-center gap-2 rounded-lg border border-ink-100 px-3 py-1.5 text-sm bg-white">
                <span class="text-ink-700/40 text-xs w-4 text-right" x-text="i+1"></span>
                <span class="flex-1" x-text="c.name"></span>
                <input type="hidden" name="{{ $field }}[]" :value="c.id">
                <button type="button" @click="move(i,-1)" class="px-1 text-ink-700/50 hover:text-ink-900" title="Move up">↑</button>
                <button type="button" @click="move(i,1)" class="px-1 text-ink-700/50 hover:text-ink-900" title="Move down">↓</button>
                <button type="button" @click="remove(i)" class="px-1 text-red-500 text-lg leading-none" title="Remove">&times;</button>
            </div>
        </template>
        <p x-show="!selected.length" class="text-xs text-ink-700/40">None selected — the default is shown.</p>
    </div>

    <div class="flex gap-2 max-w-md">
        <select x-model="addId" class="input text-sm flex-1">
            <option value="">Add a category…</option>
            <template x-for="c in available" :key="c.id"><option :value="c.id" x-text="c.name"></option></template>
        </select>
        <button type="button" @click="add()" class="btn-outline text-sm shrink-0">+ Add</button>
    </div>

    <input type="hidden" name="{{ $field }}_present" value="1">
</div>
