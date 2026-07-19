// Alpine.js is bundled through Vite (no CDN) for fewer external requests and
// reliability. Plugins + components register on the alpine:init listeners below,
// then Alpine.start() at the very end of this file boots everything.
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

window.Alpine = Alpine;
Alpine.plugin(collapse);

// Does a dropped File satisfy an input's `accept` attribute? Supports
// "image/*", "video/mp4,video/webm", ".png,.mp4" and empty (= accept all).
function fileMatchesAccept(file, accept) {
    if (!accept) return true;
    const type = (file.type || '').toLowerCase();
    const name = (file.name || '').toLowerCase();
    return accept.split(',').map((s) => s.trim().toLowerCase()).some((a) => {
        if (!a) return false;
        if (a === '*/*') return true;
        if (a.endsWith('/*')) return type.startsWith(a.slice(0, -1)); // "image/*" → "image/"
        if (a.startsWith('.')) return name.endsWith(a);
        return type === a;
    });
}
window.fileMatchesAccept = fileMatchesAccept;

// Shared Alpine component for product pages: gallery, variant selection,
// quantity, and Meta Pixel AddToCart firing. Used by every product template.
// Prevent double-submits: disable the submit button once a form starts submitting
// (covers Buy now, Place order, Add to cart fallbacks). Add data-no-lock to opt out.
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.noLock !== undefined) return;
    const btn = form.querySelector('button[type="submit"], button:not([type])');
    if (btn && !btn.disabled) {
        // Let the submit proceed, then lock to block a second click.
        setTimeout(() => { btn.disabled = true; btn.classList.add('opacity-60', 'cursor-wait'); }, 0);
        setTimeout(() => { btn.disabled = false; btn.classList.remove('opacity-60', 'cursor-wait'); }, 5000);
    }
}, true);

document.addEventListener('alpine:init', () => {
    // ── Global cart store: optimistic add, toast, slide-over drawer ──────────
    window.Alpine.store('cart', {
        count: Number(window.__cartCount || 0),
        items: [],
        subtotalText: '',
        discountLines: [],
        discountText: '',
        discount: 0,
        hints: [],
        freeShipping: false,
        drawer: false,
        toastMsg: '',
        toastShow: false,

        _apply(data) {
            this.count = data.count;
            this.items = data.items || [];
            this.subtotalText = data.subtotal_text || '';
            this.discountLines = data.discount_lines || [];
            this.discountText = data.discount_text || '';
            this.discount = data.discount || 0;
            this.hints = data.hints || [];
            this.freeShipping = !!data.free_shipping;
        },

        _adding: false,
        async add(form) {
            if (this._adding) return;            // ignore rapid double-clicks → one item
            this._adding = true;
            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: new FormData(form),
                });
                if (!res.ok) { form.submit(); return; } // fall back to normal post
                const data = await res.json();
                this._apply(data);
                // Confirm with a toast only; the cart drawer opens solely when the
                // user taps the cart icon (keeps them browsing/adding for conversion).
                this.showToast((data.added ? data.added : 'Item') + ' added to cart ✓');
            } catch (e) {
                form.submit();
            } finally {
                setTimeout(() => { this._adding = false; }, 800);
            }
        },
        async refresh() {
            try {
                const res = await fetch('/cart/mini', { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                this._apply(data);
            } catch (e) { /* ignore */ }
        },
        _removing: false,
        async remove(key) {
            if (this._removing) return;
            this._removing = true;
            try {
                const res = await fetch('/cart/remove', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ key }),
                });
                if (res.ok) this._apply(await res.json());
                else this.refresh();
            } catch (e) {
                this.refresh();
            } finally {
                this._removing = false;
            }
        },
        openDrawer() { this.refresh(); this.drawer = true; },
        showToast(msg) {
            this.toastMsg = msg;
            this.toastShow = true;
            clearTimeout(this._t);
            this._t = setTimeout(() => { this.toastShow = false; }, 3000);
        },
    });

    // ── Admin: amend order amounts (items, adjustments, shipping, discount) ──
    window.Alpine.data('orderAmend', (init) => ({
        editing: false,
        items: init.items || [],
        adjustments: init.adjustments || [],
        shipping: init.shipping || 0,
        discount: init.discount || 0,
        money(n) {
            return '৳' + (Math.round((Number(n) || 0) * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        },
        subtotal() {
            return this.items.reduce((s, it) => s + (Number(it.price) || 0) * (Number(it.quantity) || 0), 0);
        },
        adjTotal() {
            return this.adjustments.reduce((s, a) => s + (Number(a.amount) || 0), 0);
        },
        total() {
            return Math.max(0, this.subtotal() - (Number(this.discount) || 0) + (Number(this.shipping) || 0) + this.adjTotal());
        },
    }));

    // ── Header search type-ahead ─────────────────────────────────────────────
    window.Alpine.data('searchBox', () => ({
        q: '',
        results: [],
        open: false,
        loading: false,
        _timer: null,
        onInput() {
            clearTimeout(this._timer);
            if (this.q.trim().length < 2) { this.results = []; this.open = false; return; }
            this.loading = true;
            this._timer = setTimeout(() => this.fetch(), 250);
        },
        async fetch() {
            try {
                const res = await fetch('/search/suggest?q=' + encodeURIComponent(this.q));
                this.results = await res.json();
                this.open = true;
            } catch (e) { this.results = []; }
            this.loading = false;
        },
    }));

    // ── Admin: product editor (simple/variable + multi-attribute variants) ───
    window.Alpine.data('productForm', (config) => ({
        type: config.type || 'simple',
        attributes: config.attributes || [],   // [{ name, values }] (values = comma string)
        variants: config.variants || [],       // [{ attrs:{Size:'7'}, price, stock, sku }]
        offers: config.offers || [],
        price: Number(config.price) || 0,
        cost: Number(config.cost) || 0,
        transport: Number(config.transport) || 0,

        get profit() { return this.price - this.cost - this.transport; },
        get margin() { return this.price > 0 ? (this.profit / this.price * 100) : 0; },
        fmt(n) { return '৳' + Number(n).toLocaleString('en-BD', { maximumFractionDigits: 2 }); },

        addOffer() { this.offers.push({ min_qty: '', percent: '' }); },
        addAttribute() { this.attributes.push({ name: '', values: '' }); },
        removeAttribute(i) { this.attributes.splice(i, 1); this.generate(); },

        keyOf(attrs) { return Object.keys(attrs).sort().map(k => k + ':' + attrs[k]).join('|'); },
        label(attrs) { return Object.entries(attrs).map(([k, v]) => k + ': ' + v).join(' · '); },
        attrNames(v) { return Object.keys(v.attrs); },

        /** Rebuild the variant matrix as the cartesian product of attribute values. */
        generate() {
            const defs = this.attributes
                .map(a => ({ name: (a.name || '').trim(), vals: (a.values || '').split(',').map(s => s.trim()).filter(Boolean) }))
                .filter(a => a.name && a.vals.length);
            if (!defs.length) { this.variants = []; return; }

            let combos = [[]];
            for (const d of defs) {
                const next = [];
                for (const c of combos) for (const v of d.vals) next.push([...c, [d.name, v]]);
                combos = next;
            }
            const prev = {};
            for (const v of this.variants) prev[this.keyOf(v.attrs)] = v;

            this.variants = combos.map(combo => {
                const attrs = {};
                combo.forEach(([n, v]) => { attrs[n] = v; });
                const old = prev[this.keyOf(attrs)];
                return { attrs, price: old ? old.price : '', compare: old ? old.compare : '', stock: old ? old.stock : 0, sku: old ? old.sku : '' };
            });
        },
    }));

    // ── Admin: mega-menu builder ─────────────────────────────────────────────
    window.Alpine.data('menuBuilder', (initial) => ({
        items: initial || [],
        open: null,
        blank() { return { label: 'New item', type: 'link', url: '#', new_tab: false, badge: '', view_all_mobile: true, children: [], columns: [] }; },
        add() { this.items.push(this.blank()); this.open = this.items.length - 1; },
        remove(i) { this.items.splice(i, 1); this.open = null; },
        move(i, d) { const j = i + d; if (j < 0 || j >= this.items.length) return; [this.items[i], this.items[j]] = [this.items[j], this.items[i]]; this.open = j; },
        toggle(i) { this.open = this.open === i ? null : i; },
        addChild(it) { it.children.push({ label: '', url: '#', new_tab: false }); },
        removeChild(it, j) { it.children.splice(j, 1); },
        addColumn(it) { it.columns.push({ heading: 'New column', links: [{ label: '', url: '#', new_tab: false }] }); },
        removeColumn(it, k) { it.columns.splice(k, 1); },
        addLink(col) { col.links.push({ label: '', url: '#', new_tab: false }); },
        removeLink(col, l) { col.links.splice(l, 1); },
        get json() { return JSON.stringify(this.items); },
    }));

    // ── Admin: homepage section/block builder ───────────────────────────────
    window.Alpine.data('homeBuilder', (init) => ({
        blocks: (init && init.blocks) || [],
        newType: 'product_carousel',
        blankCta() { return { image: '', eyebrow: '', heading: '', subheading: '', button_text: '', button_link: '', align: 'center', height: 'md' }; },
        add() {
            this.blocks.push({ type: this.newType, enabled: true, title: '', layout: 'single',
                images: [], videos: [], source: 'new', category_id: '', limit: 10, view_all_link: '', banner: { image: '', link: '' }, cta: this.blankCta(), html: '', review_ids: [] });
        },
        remove(i) { this.blocks.splice(i, 1); },
        move(i, d) { const j = i + d; if (j < 0 || j >= this.blocks.length) return; [this.blocks[i], this.blocks[j]] = [this.blocks[j], this.blocks[i]]; },
        addImage(b) { if (!b.images) b.images = []; b.images.push({ image: '', link: '' }); },
        addVideo(b) { if (!b.videos) b.videos = []; b.videos.push({ title: '', url: '' }); },
        ensure(b) { b.images = b.images || []; b.videos = b.videos || []; b.banner = b.banner || { image: '', link: '' }; b.cta = b.cta || this.blankCta(); b.review_ids = (b.review_ids || []).map(Number); return ''; },
    }));

    // ── Admin: Discover page tile builder (image + name + link) ─────────────
    window.Alpine.data('discoverBuilder', (init) => ({
        tiles: (init && init.tiles) || [],
        add() { this.tiles.push({ image: '', name: '', link: '' }); },
        remove(i) { this.tiles.splice(i, 1); },
        move(i, d) { const j = i + d; if (j < 0 || j >= this.tiles.length) return; [this.tiles[i], this.tiles[j]] = [this.tiles[j], this.tiles[i]]; },
    }));

    // ── Admin: searchable related-product picker (upsell / cross-sell) ───────
    window.Alpine.data('relatedPicker', (all, selected, field) => ({
        all: all || [],
        selected: (selected || []).map(Number),
        field,
        q: '',
        open: false,
        get results() {
            const term = this.q.trim().toLowerCase();
            const matches = this.all
                .filter(p => !this.selected.includes(p.id))
                .filter(p => term === '' || p.name.toLowerCase().includes(term));
            // Show everything while searching; cap only the initial (no-term) list.
            return term === '' ? matches.slice(0, 100) : matches;
        },
        get chosen() {
            return this.selected.map(id => this.all.find(p => p.id === id)).filter(Boolean);
        },
        add(id) { if (!this.selected.includes(id)) this.selected.push(id); this.q = ''; this.open = false; },
        remove(id) { this.selected = this.selected.filter(x => x !== id); },
    }));

    // Editorial "story sections" builder — used on the product form and in the
    // content-template manager. Manages an array of {image,heading,body,layout}.
    window.Alpine.data('sectionBuilder', (initial, opts) => ({
        sections: Array.isArray(initial) ? initial : [],
        uploadUrl: opts.uploadUrl,
        saveUrl: opts.saveUrl || null,
        csrf: opts.csrf,
        add() {
            this.sections.push({ image: '', heading: '', body: '', layout: this.sections.length % 2 ? 'left' : 'right' });
        },
        remove(i) { this.sections.splice(i, 1); },
        move(i, dir) {
            const j = i + dir;
            if (j < 0 || j >= this.sections.length) return;
            const s = this.sections;
            [s[i], s[j]] = [s[j], s[i]];
        },
        async upload(i, e) {
            const file = e.target.files[0];
            if (!file) return;
            const fd = new FormData();
            fd.append('image', file);
            fd.append('_token', this.csrf);
            try {
                const r = await fetch(this.uploadUrl, { method: 'POST', body: fd, headers: { Accept: 'application/json' } });
                const d = await r.json();
                if (d.url) this.sections[i].image = d.url;
            } catch (_) { alert('Image upload failed.'); }
            e.target.value = '';
        },
        // Pick this section's image from the shared media library instead of uploading.
        pickLibrary(i) {
            this.$store.mediaLib.openWith((url) => { this.sections[i].image = url; }, 'sections');
        },
        applyTemplate(e) {
            const raw = e.target.selectedOptions[0] && e.target.selectedOptions[0].dataset.sections;
            if (raw && confirm('Replace the current sections with this template?')) {
                this.sections = JSON.parse(raw);
            }
            e.target.value = '';
        },
        async saveAsTemplate() {
            if (!this.saveUrl) return;
            const name = prompt('Save these sections as a template. Name:');
            if (!name) return;
            const fd = new FormData();
            fd.append('_token', this.csrf);
            fd.append('name', name);
            fd.append('content_sections_json', this.json);
            try {
                const r = await fetch(this.saveUrl, { method: 'POST', body: fd, headers: { Accept: 'application/json' } });
                alert(r.ok ? 'Saved as template.' : 'Could not save template.');
            } catch (_) { alert('Could not save template.'); }
        },
        get json() { return JSON.stringify(this.sections); },
    }));

    // ── Shared media picker ──────────────────────────────────────────────────
    // One global modal (included in the admin layout) lets any field pick an
    // existing image from the library or upload a new one from the device.
    // window.MEDIA = { picker, upload, csrf } is set by the modal partial.
    window.Alpine.store('mediaLib', {
        open: false,
        loading: false,
        uploading: false,
        tab: 'library',        // 'library' | 'device'
        items: [],
        folders: [],
        q: '',
        folder: '',            // upload target folder (where new uploads go)
        browseFolder: '',      // library filter ('' = show ALL folders)
        multi: false,          // multi-select mode (add several at once)
        selected: [],          // chosen urls while in multi mode
        dragOver: false,       // a file is being dragged over the modal
        _cb: null,
        openWith(cb, folder, opts) {
            this._cb = cb;
            this.folder = folder || '';
            this.browseFolder = '';        // browse everything by default
            this.multi = !!(opts && opts.multi);
            this.selected = [];
            this.tab = 'library';
            this.q = '';
            this.open = true;
            this.load();
        },
        close() { this.open = false; this._cb = null; this.multi = false; this.selected = []; },
        isSelected(url) { return this.selected.includes(url); },
        toggle(url) {
            const i = this.selected.indexOf(url);
            if (i === -1) this.selected.push(url); else this.selected.splice(i, 1);
        },
        // Tile click: toggle in multi mode, pick-and-close in single mode.
        pick(url) { if (this.multi) this.toggle(url); else this.choose(url); },
        // Confirm a multi selection: hand the whole array to the callback once.
        confirm() {
            if (this._cb && this.selected.length) this._cb(this.selected.slice());
            this.close();
        },
        async load() {
            if (!window.MEDIA) return;
            this.loading = true;
            try {
                const url = new URL(window.MEDIA.picker, window.location.origin);
                if (this.q) url.searchParams.set('q', this.q);
                if (this.browseFolder) url.searchParams.set('folder', this.browseFolder);
                const r = await fetch(url, { headers: { Accept: 'application/json' } });
                const d = await r.json();
                this.items = d.items || [];
                this.folders = d.folders || [];
            } catch (_) { this.items = []; }
            this.loading = false;
        },
        choose(url) { if (this._cb) this._cb(url); this.close(); },
        // File picker <input> change handler — delegates to uploadFiles().
        uploadDevice(e) {
            const files = e.target.files;
            e.target.value = '';
            return this.uploadFiles(files);
        },
        // Upload one or more image files (from the picker OR a drag-and-drop),
        // add them to the library, then select/return them like a device upload.
        async uploadFiles(fileList) {
            let files = Array.from(fileList || []).filter((f) => (f.type || '').startsWith('image/'));
            if (!this.multi) files = files.slice(0, 1);   // single-pick: first image only
            if (!files.length || !window.MEDIA) return;

            this.uploading = true;
            this.dragOver = false;
            const urls = [];
            for (const file of files) {
                const fd = new FormData();
                fd.append('image', file);
                fd.append('_token', window.MEDIA.csrf);
                if (this.folder) fd.append('folder', this.folder);
                try {
                    const r = await fetch(window.MEDIA.upload, { method: 'POST', body: fd, headers: { Accept: 'application/json' } });
                    const d = await r.json();
                    if (d.url) urls.push(d.url);
                } catch (_) { /* keep going with the rest */ }
            }
            this.uploading = false;

            if (!urls.length) { alert('Upload failed.'); return; }

            if (this.multi) {
                // Add the uploads to the selection and return to the library grid.
                urls.forEach((u) => { if (!this.selected.includes(u)) this.selected.push(u); });
                this.tab = 'library';
                this.load();
            } else {
                this.choose(urls[0]);
            }
        },
    });

    // Reusable single-image field: device upload OR media-library pick. Backend
    // reads an uploaded file `name` first, else the picked/remote URL `name_url`
    // (see resolve_media()). Rendered by the <x-media-field> Blade component.
    window.Alpine.data('mediaField', (initial, folder) => ({
        value: initial || '',   // library / remote URL (posted as name_url)
        deviceName: '',         // filename chosen from device (file posts via name)
        cleared: false,         // user removed an existing image (posted as name_cleared)
        folder: folder || 'uploads',
        _localPreview: '',
        get preview() { return this.value || this._localPreview || ''; },
        pickLibrary() {
            this.$store.mediaLib.openWith((url) => {
                this.value = url;
                this.deviceName = '';
                this._localPreview = '';
                this.cleared = false;
                this.$refs.file.value = '';       // library pick wins over any stale file
            }, this.folder);
        },
        chooseDevice() { this.$refs.file.click(); },
        onDevice(e) {
            const file = e.target.files[0];
            if (!file) return;
            this.value = '';                       // device upload wins; clear the URL
            this.deviceName = file.name;
            this._localPreview = URL.createObjectURL(file);
            this.cleared = false;
        },
        // Drag-and-drop an image straight onto the field.
        over: false,
        onDrop(e) {
            this.over = false;
            const file = Array.from(e.dataTransfer?.files || []).find((f) => (f.type || '').startsWith('image/'));
            if (!file) return;
            const dt = new DataTransfer();          // route it through the file input so it submits
            dt.items.add(file);
            this.$refs.file.files = dt.files;
            this.value = '';
            this.deviceName = file.name;
            this._localPreview = URL.createObjectURL(file);
            this.cleared = false;
        },
        clear() {
            this.value = '';
            this.deviceName = '';
            this._localPreview = '';
            this.cleared = true;
            this.$refs.file.value = '';
        },
    }));

    // Reusable drag-and-drop for a native <input type="file" x-ref="input">.
    // Dropped files that match the input's `accept` are assigned to the input
    // (merged for multiple, replaced for single) and a change event fires, so
    // they upload with the form exactly like a normal file-picker selection.
    // Shows instant thumbnails for pending files.
    window.Alpine.data('fileDrop', () => ({
        over: false,
        previews: [],
        init() { this.sync(); },
        sync() {
            const input = this.$refs.input;
            this.previews = Array.from(input?.files || []).map((f) => ({
                name: f.name,
                isImage: (f.type || '').startsWith('image/'),
                url: (f.type || '').startsWith('image/') ? URL.createObjectURL(f) : '',
            }));
        },
        drop(e) {
            this.over = false;
            const input = this.$refs.input;
            const dropped = Array.from(e.dataTransfer?.files || []).filter((f) => fileMatchesAccept(f, input?.accept));
            if (!input || !dropped.length) return;

            const dt = new DataTransfer();
            if (input.multiple) {
                Array.from(input.files || []).forEach((f) => dt.items.add(f));   // keep existing selection
                dropped.forEach((f) => dt.items.add(f));
            } else {
                dt.items.add(dropped[0]);
            }
            input.files = dt.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            this.sync();
        },
    }));

    // ── Mobile off-canvas drawer ─────────────────────────────────────────────
    // Global open state + body scroll-lock (compensating scrollbar width so
    // there is no layout shift). The header trigger and the drawer (which lives
    // at the top level of the layout, outside any backdrop-filter ancestor) both
    // talk to this store.
    window.Alpine.store('mobileNav', {
        open: false,
        show() {
            this.open = true;
            const sw = window.innerWidth - document.documentElement.clientWidth;
            document.body.style.overflow = 'hidden';
            if (sw > 0) document.body.style.paddingRight = sw + 'px';
        },
        close() {
            this.open = false;
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        },
        toggle() { this.open ? this.close() : this.show(); },
    });

    // Drawer behaviour: swipe-left to close + a lightweight focus trap.
    window.Alpine.data('mobileDrawer', () => ({
        _x: null,
        init() {
            // Move focus into the panel when it opens (accessibility).
            this.$watch('$store.mobileNav.open', (v) => {
                if (v) this.$nextTick(() => this.$refs.panel?.focus());
            });
        },
        onStart(e) { this._x = e.changedTouches[0].clientX; },
        onMove(e) {
            if (this._x === null) return;
            if (e.changedTouches[0].clientX - this._x < -60) { this.$store.mobileNav.close(); this._x = null; }
        },
        onEnd() { this._x = null; },
        trap(e) {
            if (e.key !== 'Tab') return;
            const f = this.$refs.panel.querySelectorAll('a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])');
            if (!f.length) return;
            const first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        },
    }));

    // Meta Integration Debug page — runs the independent Graph API testers.
    // Registered here (not inline) so it's guaranteed available at alpine:init.
    window.Alpine.data('metaDebug', () => ({
        running: '',
        result: null,
        graphPath: 'me',
        get csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; },
        async run(what, extra = {}) {
            this.running = what;
            this.result = null;
            try {
                const res = await fetch('/admin/meta/debug/test/' + what, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                    body: JSON.stringify(extra),
                });
                this.result = await res.json();
            } catch (e) {
                this.result = { ok: false, what, notes: ['Request failed: ' + e.message], calls: [] };
            }
            this.running = '';
        },
        pretty(o) { try { return JSON.stringify(o, null, 2); } catch (_) { return String(o); } },
        copy(o) { navigator.clipboard.writeText(typeof o === 'string' ? o : this.pretty(o)); },
    }));

    window.Alpine.data('productPage', (config) => ({
        img: config.image || '',
        qty: 1,
        hasVariants: !!config.hasVariants,
        attributes: config.attributes || [],     // [{name, values:[]}]
        variantList: config.variants || [],       // [{id, attrs:{}, price, stock}]
        selected: {},                              // {Size:'7', Color:'Gold'}
        basePrice: config.price || 0,
        baseCompare: config.compare || 0,          // simple-product original price
        // Catalog retailer_id ("prod-{id}") so Pixel/CAPI events link to catalog
        // products — must match MetaProductMapper::retailerId on the server.
        contentId: 'prod-' + (config.id || ''),
        name: config.name || '',
        offers: (config.offers || []).map(o => ({ min_qty: Number(o.min_qty), percent: Number(o.percent) })),

        init() {
            // Pre-select any attribute that has only one value.
            for (const a of this.attributes) {
                if ((a.values || []).length === 1) this.selected[a.name] = a.values[0];
            }
        },

        // The variant matching every selected attribute (null until all chosen).
        get matched() {
            if (!this.hasVariants) return null;
            if (this.attributes.some(a => !this.selected[a.name])) return null;
            return this.variantList.find(v =>
                this.attributes.every(a => String(v.attrs[a.name]) === String(this.selected[a.name]))) || null;
        },
        // variant_id posted to the cart: id when matched, '' when variable-unmatched, 'none' when simple.
        get variant() { return this.hasVariants ? (this.matched ? String(this.matched.id) : '') : 'none'; },
        get variantStock() { return this.matched ? this.matched.stock : null; },

        selectAttr(name, val) { this.selected[name] = val; },
        isSelected(name, val) { return String(this.selected[name]) === String(val); },
        // Disable a value if no variant with it is in stock.
        valueInStock(name, val) {
            return this.variantList.some(v => String(v.attrs[name]) === String(val) && v.stock > 0);
        },

        get unitPrice() {
            if (this.hasVariants) return this.matched ? this.matched.price : this.basePrice;
            return this.basePrice;
        },
        // "Compare-at" (original) price for the current selection, 0 if none.
        get compareAt() {
            if (this.hasVariants) return this.matched ? (this.matched.compare || 0) : 0;
            return this.baseCompare || 0;
        },
        get onSale() { return this.compareAt > this.unitPrice; },
        get discountPct() { return this.onSale ? Math.round((1 - this.unitPrice / this.compareAt) * 100) : 0; },
        get offerPercent() {
            let best = 0;
            for (const o of this.offers) {
                if (this.qty >= o.min_qty && o.percent > best) best = o.percent;
            }
            return best;
        },
        get price() {
            return this.offerPercent > 0
                ? Math.round(this.unitPrice * (1 - this.offerPercent / 100) * 100) / 100
                : this.unitPrice;
        },
        get lineTotal() { return this.price * this.qty; },
        get savings() { return Math.round((this.unitPrice - this.price) * this.qty * 100) / 100; },
        get canBuy() {
            if (!this.hasVariants) return true;
            return !!this.matched && this.matched.stock > 0;
        },
        fmt(n) { return '৳' + Number(n).toLocaleString(); },
        priceText() {
            if (this.hasVariants && !this.matched) {
                const prices = this.variantList.map(v => v.price).filter(p => p > 0);
                return prices.length ? 'From ' + this.fmt(Math.min(...prices)) : this.fmt(this.basePrice);
            }
            return this.fmt(this.price);
        },
        inc() { this.qty++; },
        dec() { this.qty = Math.max(1, this.qty - 1); },

        fireAddToCart(form) {
            // Variant-aware content id (matches the catalog retailer_id).
            const cid = (this.hasVariants && this.matched)
                ? (this.contentId + '-var-' + this.matched.id)
                : this.contentId;

            // One event id shared by the browser Pixel and the server CAPI call.
            const eventId = 'AddToCart.' + ((self.crypto && crypto.randomUUID)
                ? crypto.randomUUID()
                : (Date.now() + '-' + Math.random().toString(16).slice(2)));

            // Hand the id to the cart POST so the server fires the matching,
            // deduplicated CAPI AddToCart (CartController@add reads `event_id`).
            if (form) {
                let input = form.querySelector('input[name="event_id"]');
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'event_id';
                    form.appendChild(input);
                }
                input.value = eventId;
            }

            if (window.track) {
                window.track('AddToCart', {
                    content_ids: [cid],
                    content_name: this.name,
                    content_type: 'product',
                    value: this.price * this.qty,
                    currency: 'BDT',
                }, { eventID: eventId });
            }
        },
    }));
});

// Boot Alpine after all stores/components/plugins are registered above.
Alpine.start();

// ── Storefront scroll-reveal (premium motion) ────────────────────────────────
// Sections fade-up as they enter the viewport; cards inside a section stagger.
// Gated to the storefront ([data-shop]) so the admin stays instant, and skipped
// entirely for reduced-motion users. Content is untouched when JS doesn't run.
(function () {
    if (!document.body.hasAttribute('data-shop')) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    if (!('IntersectionObserver' in window)) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('reveal-in');
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

    // Above-the-fold elements animate immediately — they are never put in a
    // hidden state that depends on observer callbacks firing. Only below-fold
    // elements wait to be revealed (observer, plus the scroll sweep below as a
    // fallback for environments that throttle IntersectionObserver).
    const activate = (el, delay) => {
        if (delay) el.style.setProperty('--reveal-delay', delay);
        el.classList.add('reveal-init');
        if (el.getBoundingClientRect().top < window.innerHeight - 20) {
            el.classList.add('reveal-in');
        } else {
            observer.observe(el);
        }
    };

    document.querySelectorAll('main section').forEach((el) => activate(el, ''));
    // Stagger cards in reading order, restarting every row-ish group of 4.
    document.querySelectorAll('main .group.relative.block, main .card')
        .forEach((el, i) => activate(el, `${(i % 4) * 0.08}s`));

    const sweep = () => {
        document.querySelectorAll('.reveal-init:not(.reveal-in)').forEach((el) => {
            const r = el.getBoundingClientRect();
            if (r.top < window.innerHeight + 80 && r.bottom > -80) el.classList.add('reveal-in');
        });
    };
    window.addEventListener('scroll', sweep, { passive: true });
    setTimeout(sweep, 2000);
})();
