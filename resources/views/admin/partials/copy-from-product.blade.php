{{--
    "Copy from another product" — a small product picker for the product edit
    form. It fetches the picked product's copyable data and hands it to the
    editor around it as a bubbling `copy-from-product` event:

        { kind, mode: 'replace' | 'append', name, data }

    The editor listens with @copy-from-product and ignores other kinds.

    @param string   $kind     'custom_fields' | 'pdp_points'
    @param string   $noun     what is copied, for the labels ("custom fields")
    @param int|null $exceptId the product being edited, left out of the results
--}}
<div class="mt-3" x-data="{
        kind: @js($kind),
        open: false, q: '', results: [], searching: false,
        picked: null, data: null, busy: false, error: '',
        timer: null,
        search() {
            clearTimeout(this.timer);
            this.timer = setTimeout(async () => {
                const q = this.q.trim();
                if (q.length < 2 && !/^\d+$/.test(q)) { this.results = []; return; }
                this.searching = true;
                try {
                    const r = await fetch(@js(route('admin.products.copy-search')) + '?except={{ (int) ($exceptId ?? 0) }}&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                    this.results = (await r.json()).results || [];
                } catch (e) { this.results = []; }
                this.searching = false;
            }, 250);
        },
        async pick(p) {
            this.busy = true; this.error = ''; this.picked = p; this.data = null;
            try {
                const r = await fetch(p.copyable_url, { headers: { Accept: 'application/json' } });
                if (!r.ok) throw new Error();
                const json = await r.json();
                this.data = json[this.kind] || [];
            } catch (e) { this.error = 'Could not load that product. Try again.'; this.picked = null; }
            this.busy = false;
        },
        apply(mode) {
            this.$dispatch('copy-from-product', { kind: this.kind, mode, name: this.picked.name, data: JSON.parse(JSON.stringify(this.data)) });
            this.reset();
        },
        reset() { this.open = false; this.q = ''; this.results = []; this.picked = null; this.data = null; this.error = ''; },
     }">
    <button type="button" x-show="!open" @click="open = true; $nextTick(() => $refs.q.focus())" class="text-xs text-gold-700 underline">
        Copy {{ $noun }} from another product…
    </button>

    <div x-show="open" x-cloak class="rounded-md border border-ink-100 bg-ink-50/60 p-3">
        <div x-show="!picked">
            <div>
                <div class="flex items-center gap-2">
                    <input x-ref="q" x-model="q" @input="search()" @keydown.escape.prevent="reset()" @keydown.enter.prevent="results[0] && pick(results[0])"
                           class="input flex-1 text-sm" placeholder="Search by product name, SKU or ID">
                    <button type="button" @click="reset()" class="text-xs text-ink-700/60 hover:text-ink-900">Cancel</button>
                </div>
                <p x-show="searching" class="text-xs text-ink-700/50 mt-2">Searching…</p>
                <p x-show="!searching && q.trim().length >= 2 && !results.length" class="text-xs text-ink-700/50 mt-2">No products match.</p>
                <ul x-show="results.length" class="mt-2 max-h-64 overflow-y-auto divide-y divide-ink-100 rounded-md border border-ink-100 bg-white">
                    <template x-for="p in results" :key="p.id">
                        <li>
                            <button type="button" @click="pick(p)" class="w-full flex items-center gap-3 px-3 py-2 text-left text-sm hover:bg-ink-50">
                                <img x-show="p.thumbnail" :src="p.thumbnail" alt="" class="w-8 h-8 rounded object-cover shrink-0">
                                <span class="flex-1 min-w-0 truncate" x-text="p.name"></span>
                                <span class="text-xs text-ink-700/50 shrink-0" x-text="[p.serial ? '#' + p.serial : '', p.status !== 'published' ? p.status : ''].filter(Boolean).join(' · ')"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
        </div>

        <p x-show="error" class="text-xs text-red-600 mt-2" x-text="error"></p>

        <template x-if="picked">
            <div class="text-sm">
                <p x-show="busy" class="text-ink-700/60">Loading <span x-text="picked.name"></span>…</p>
                <div x-show="!busy && data">
                    <p class="font-medium" x-text="picked.name"></p>
                    <template x-if="data && data.length">
                        <ul class="mt-1 space-y-0.5 text-xs text-ink-700/80">
                            <template x-for="(row, i) in data" :key="i">
                                <li x-text="kind === 'pdp_points' ? (row.title + (row.text ? ' — ' + row.text : '')) : (row.label + ': ' + row.value + (row.show ? '' : ' (hidden)'))"></li>
                            </template>
                        </ul>
                    </template>
                    <p x-show="data && !data.length" class="text-xs text-ink-700/60 mt-1">This product has no {{ $noun }} to copy.</p>
                    <div class="flex flex-wrap items-center gap-2 mt-3">
                        <button type="button" x-show="data && data.length" @click="apply('replace')" class="btn-primary text-xs px-3 py-1.5">Replace mine with these</button>
                        <button type="button" x-show="data && data.length" @click="apply('append')" class="btn-outline text-xs px-3 py-1.5">Add to the end of mine</button>
                        <button type="button" @click="picked = null; data = null" class="text-xs text-ink-700/60 hover:text-ink-900">Pick another</button>
                        <button type="button" @click="reset()" class="text-xs text-ink-700/60 hover:text-ink-900">Cancel</button>
                    </div>
                    <p class="text-xs text-ink-700/50 mt-2">Nothing is saved until you save this product.</p>
                </div>
            </div>
        </template>
    </div>
</div>
