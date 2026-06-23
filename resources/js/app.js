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
                this.showToast((data.added ? data.added : 'Item') + ' added to cart ✓');
                this.drawer = true;
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
        openDrawer() { this.refresh(); this.drawer = true; },
        showToast(msg) {
            this.toastMsg = msg;
            this.toastShow = true;
            clearTimeout(this._t);
            this._t = setTimeout(() => { this.toastShow = false; }, 3000);
        },
    });

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
                return { attrs, price: old ? old.price : '', stock: old ? old.stock : 0, sku: old ? old.sku : '' };
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
        add() {
            this.blocks.push({ type: this.newType, enabled: true, title: '', layout: 'single',
                images: [], videos: [], source: 'new', category_id: '', limit: 10, view_all_link: '', banner: { image: '', link: '' }, html: '' });
        },
        remove(i) { this.blocks.splice(i, 1); },
        move(i, d) { const j = i + d; if (j < 0 || j >= this.blocks.length) return; [this.blocks[i], this.blocks[j]] = [this.blocks[j], this.blocks[i]]; },
        addImage(b) { if (!b.images) b.images = []; b.images.push({ image: '', link: '' }); },
        addVideo(b) { if (!b.videos) b.videos = []; b.videos.push({ title: '', url: '' }); },
        ensure(b) { b.images = b.images || []; b.videos = b.videos || []; b.banner = b.banner || { image: '', link: '' }; return ''; },
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
            return this.all
                .filter(p => !this.selected.includes(p.id))
                .filter(p => term === '' || p.name.toLowerCase().includes(term))
                .slice(0, 8);
        },
        get chosen() {
            return this.selected.map(id => this.all.find(p => p.id === id)).filter(Boolean);
        },
        add(id) { if (!this.selected.includes(id)) this.selected.push(id); this.q = ''; this.open = false; },
        remove(id) { this.selected = this.selected.filter(x => x !== id); },
    }));

    window.Alpine.data('productPage', (config) => ({
        img: config.image || '',
        qty: 1,
        hasVariants: !!config.hasVariants,
        attributes: config.attributes || [],     // [{name, values:[]}]
        variantList: config.variants || [],       // [{id, attrs:{}, price, stock}]
        selected: {},                              // {Size:'7', Color:'Gold'}
        basePrice: config.price || 0,
        contentId: String(config.id || ''),
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

        fireAddToCart() {
            if (window.track) {
                window.track('AddToCart', {
                    content_ids: [this.contentId],
                    content_name: this.name,
                    content_type: 'product',
                    value: this.price * this.qty,
                    currency: 'BDT',
                });
            }
        },
    }));
});
