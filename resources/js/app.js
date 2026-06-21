// Shared Alpine component for product pages: gallery, variant selection,
// quantity, and Meta Pixel AddToCart firing. Used by every product template.
document.addEventListener('alpine:init', () => {
    // ── Global cart store: optimistic add, toast, slide-over drawer ──────────
    window.Alpine.store('cart', {
        count: Number(window.__cartCount || 0),
        items: [],
        subtotalText: '',
        drawer: false,
        toastMsg: '',
        toastShow: false,

        async add(form) {
            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: new FormData(form),
                });
                if (!res.ok) { form.submit(); return; } // fall back to normal post
                const data = await res.json();
                this.count = data.count;
                this.items = data.items || [];
                this.subtotalText = data.subtotal_text || '';
                this.showToast((data.added ? data.added : 'Item') + ' added to cart ✓');
                this.drawer = true;
            } catch (e) {
                form.submit();
            }
        },
        async refresh() {
            try {
                const res = await fetch('/cart/mini', { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                this.count = data.count;
                this.items = data.items || [];
                this.subtotalText = data.subtotal_text || '';
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
        variant: config.hasVariants ? null : 'none',
        variants: config.variants || {},
        basePrice: config.price || 0,
        contentId: String(config.id || ''),
        name: config.name || '',
        offers: (config.offers || []).map(o => ({ min_qty: Number(o.min_qty), percent: Number(o.percent) })),

        // Unit price for the currently-selected variant, before any quantity offer.
        get unitPrice() {
            if (this.variant && this.variant !== 'none' && this.variants[this.variant]) {
                return this.variants[this.variant].price;
            }
            return this.basePrice;
        },
        // Best offer percent that applies at the current quantity (0 if none).
        get offerPercent() {
            let best = 0;
            for (const o of this.offers) {
                if (this.qty >= o.min_qty && o.percent > best) best = o.percent;
            }
            return best;
        },
        // Discounted unit price after applying the best applicable offer.
        get price() {
            return this.offerPercent > 0
                ? Math.round(this.unitPrice * (1 - this.offerPercent / 100) * 100) / 100
                : this.unitPrice;
        },
        get lineTotal() { return this.price * this.qty; },
        get savings() { return Math.round((this.unitPrice - this.price) * this.qty * 100) / 100; },
        get canBuy() {
            return !this.hasVariants || (this.variant && this.variant !== 'none');
        },
        fmt(n) { return '৳' + Number(n).toLocaleString(); },
        priceText() {
            return this.fmt(this.price);
        },
        selectVariant(id) { this.variant = String(id); },
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
