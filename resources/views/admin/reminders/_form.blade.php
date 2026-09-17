{{-- The reminder form, shared by create and edit.

     Expects: $reminder, $products, $initial (items, due_date, due_time,
     matched), $notices, $formUrl, $formVerb ('POST' or 'PUT'), $submitLabel.

     The items picker is the manual order form's product list with the option
     picker it lacks: a lead asks about "the opal band in size 8", and an order
     made from the reminder should not have to ask again. --}}
@php
    $customerUrl = auth()->user()->canAccess('customers')
        ? route('admin.customers.show', ['customer' => '__ID__'])
        : null;
    $matched = $initial['matched'];
@endphp

@if($errors->any())
    <div class="mb-5 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">
        <p class="font-medium">The reminder was not saved:</p>
        <ul class="mt-1 list-disc list-inside">
            @foreach($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(!empty($notices))
    <div class="mb-5 rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm">
        <ul class="space-y-1 list-disc list-inside">
            @foreach($notices as $notice)
                <li>{{ $notice }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ $formUrl }}"
      x-data="reminderForm({{ Js::from($products) }}, {{ Js::from($initial + [
          'phone' => (string) old('phone', $reminder->phone),
          'timezone' => config('store.timezone', 'Asia/Dhaka'),
          'customerSearchUrl' => route('admin.orders.customer-search'),
          'customerUrl' => $customerUrl,
      ]) }})"
      class="grid lg:grid-cols-3 gap-6">
    @csrf
    @if($formVerb === 'PUT') @method('PUT') @endif
    @if(! $reminder->exists && $reminder->abandoned_cart_id)
        <input type="hidden" name="abandoned_cart_id" value="{{ $reminder->abandoned_cart_id }}">
    @endif

    <div class="lg:col-span-2 space-y-6">
        <div class="card p-6">
            <h2 class="font-semibold mb-4">Who to call</h2>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="label" for="reminder-phone">Phone *</label>
                    <input id="reminder-phone" name="phone" value="{{ old('phone', $reminder->phone) }}" class="input" required maxlength="20"
                           inputmode="tel" autocomplete="off" placeholder="01XXXXXXXXX"
                           x-ref="phone" @input.debounce.400ms="lookup()" @change="lookup()">
                    @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="reminder-name">Name</label>
                    <input id="reminder-name" name="name" value="{{ old('name', $reminder->name) }}" class="input" maxlength="120" x-ref="name">
                    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                {{-- Whose number it is, when the shop knows them — looked up
                     through the order form's customer search as it is typed.
                     The reminder is linked to that customer when it is saved. --}}
                <div class="sm:col-span-2" x-show="matched" @if(! $matched) style="display: none" @endif>
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-md bg-gold-50 px-3 py-2 text-xs text-ink-700/70">
                        <span>
                            This number belongs to
                            <span class="font-medium text-ink-900" x-text="matched ? (matched.name || matched.phone) : ''">{{ $matched['name'] ?? '' }}</span>,
                            <span x-text="matched ? matched.total_orders + (matched.total_orders === 1 ? ' order' : ' orders') + ' so far.' : ''">{{ $matched ? $matched['total_orders'].($matched['total_orders'] === 1 ? ' order' : ' orders').' so far.' : '' }}</span>
                        </span>
                        <span x-show="matched && matched.blacklisted" @if(empty($matched['blacklisted'])) style="display: none" @endif
                              class="badge bg-red-100 text-red-700 text-[10px]">Blacklisted</span>
                        @if($customerUrl)
                            <a :href="matched ? customerLink(matched.id) : '#'" href="{{ $matched ? route('admin.customers.show', $matched['id']) : '#' }}"
                               class="text-gold-700 hover:underline">Open customer →</a>
                        @endif
                    </p>
                </div>
            </div>
        </div>

        <div class="card p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-semibold">Items <span class="text-xs font-normal text-ink-700/60">— optional</span></h2>
                <button type="button" @click="addItem()" class="text-sm text-gold-700 hover:underline">+ Add item</button>
            </div>

            <template x-for="(item, i) in items" :key="item.uid">
                {{-- items-start, not the order form's items-end: the price line
                     under the product would otherwise push the other boxes
                     down out of line with it. --}}
                <div class="grid grid-cols-12 gap-2 items-start mb-3 pb-3 border-b border-ink-100 last:border-0">
                    <div class="col-span-6">
                        <label class="label text-xs" :for="'item-product-' + item.uid">Product</label>
                        <select :id="'item-product-' + item.uid" :name="`items[${i}][product_id]`" x-model.number="item.product_id"
                                @change="item.variant_id = ''; item.price = ''" class="input py-1.5 text-sm">
                            <option value="">Choose…</option>
                            <template x-for="p in products" :key="p.id">
                                <option :value="p.id" :selected="p.id === item.product_id"
                                        x-text="p.name + (p.sku ? ' · ' + p.sku : '') + (p.on_sale ? '' : ' — not on sale')"></option>
                            </template>
                        </select>
                        {{-- The price she was shown, carried from a lead's
                             basket; blank means the catalogue's, filled in on
                             save. Shown so the figure an order will start from
                             is never a surprise. --}}
                        <input type="hidden" :name="`items[${i}][price]`" :value="item.price">
                        <p class="mt-1 text-[11px] text-ink-700/60" x-show="unitPrice(item) !== null"
                           x-text="money(unitPrice(item)) + ' each' + (item.price !== '' && item.price !== null ? ', the price they were shown' : '')"></p>
                    </div>
                    <div class="col-span-3">
                        <label class="label text-xs" :for="'item-option-' + item.uid">Option</label>
                        {{-- Disabled, and so not posted, for a product without
                             options. Left on "Not decided", the order form says
                             so and asks for the price by hand. --}}
                        <select :id="'item-option-' + item.uid" :name="`items[${i}][variant_id]`" x-model.number="item.variant_id"
                                :disabled="!variantsOf(item).length" @change="item.price = ''" class="input py-1.5 text-sm">
                            <option value="" x-text="variantsOf(item).length ? 'Not decided' : '—'"></option>
                            <template x-for="v in variantsOf(item)" :key="v.id">
                                <option :value="v.id" :selected="v.id === item.variant_id"
                                        x-text="v.label + (v.active ? '' : ' — unavailable')"></option>
                            </template>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="label text-xs" :for="'item-qty-' + item.uid">Qty</label>
                        <input :id="'item-qty-' + item.uid" type="number" :name="`items[${i}][qty]`" x-model.number="item.qty"
                               min="1" max="99" required class="input py-1.5 text-sm">
                    </div>
                    <div class="col-span-1 pt-8 text-right">
                        <button type="button" @click="items.splice(i, 1)" class="text-red-600 hover:underline text-xs" aria-label="Remove item">✕</button>
                    </div>
                </div>
            </template>

            <p x-show="!items.length" class="text-sm text-ink-700/60">
                No items. Add the pieces they asked about, and "Create order" starts with them.
            </p>
            @error('items')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="card p-6">
            <label class="label" for="reminder-notes">Notes</label>
            <textarea id="reminder-notes" name="notes" rows="3" maxlength="2000" class="input"
                      placeholder="Wants it before Eid · call after 8 PM · ask about the gift box">{{ old('notes', $reminder->notes) }}</textarea>
            @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="card p-6 h-fit space-y-4">
        <h2 class="font-semibold">When to call</h2>

        {{-- Quick picks, worked out in Dhaka time whatever the computer's own
             clock says. "In 3 days" means 11 AM, the shop's call-back hour, not
             whatever minute the form happened to be opened in. --}}
        <div class="flex flex-wrap gap-1.5">
            <button type="button" @click="preset('hour')" class="btn-outline py-1 px-2.5 text-xs">In 1 hour</button>
            <button type="button" @click="preset('evening')" x-show="eveningAhead()" class="btn-outline py-1 px-2.5 text-xs">This evening 7 PM</button>
            <button type="button" @click="preset('tomorrow')" class="btn-outline py-1 px-2.5 text-xs">Tomorrow 11 AM</button>
            <button type="button" @click="preset('days3')" class="btn-outline py-1 px-2.5 text-xs">In 3 days</button>
        </div>

        {{-- Stacked: side by side, a third of a laptop screen cut the browser's
             own date box down to "mm/d". --}}
        <div class="space-y-3">
            <div>
                <label class="label text-xs" for="reminder-due-date">Date *</label>
                <input id="reminder-due-date" type="date" name="due_date" value="{{ $initial['due_date'] }}" x-model="due_date" required class="input py-1.5 text-sm">
            </div>
            <div>
                <label class="label text-xs" for="reminder-due-time">Time *</label>
                <input id="reminder-due-time" type="time" name="due_time" value="{{ $initial['due_time'] }}" x-model="due_time" required class="input py-1.5 text-sm">
            </div>
        </div>
        @error('due_date')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        @error('due_time')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        <p class="text-[11px] text-ink-700/60" x-text="readout() ? 'Call on ' + readout() + ', Bangladesh time.' : 'Bangladesh time.'">Bangladesh time.</p>

        <button class="btn-primary w-full">{{ $submitLabel }}</button>
        <a href="{{ route('admin.reminders.index', ['tab' => $reminder->exists ? $reminder->tab() : 'due']) }}"
           class="block text-center text-xs text-ink-700/70 hover:underline">Cancel</a>
    </div>
</form>

<script>
    function reminderForm(products, options) {
        let uid = 0;
        const row = (r) => ({
            uid: ++uid,
            product_id: r.product_id || '',
            variant_id: r.variant_id || '',
            qty: r.qty || 1,
            price: (r.price === null || r.price === undefined) ? '' : r.price,
        });
        // bd_phone(), so "+880 1712-345678" is looked up as 01712345678.
        const normalPhone = (raw) => {
            let d = String(raw || '').replace(/\D/g, '');
            if (d.startsWith('880')) d = d.slice(3);
            if (d.length === 10 && d.startsWith('1')) d = '0' + d;
            return d;
        };
        const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        return {
            products,
            items: (options.items || []).map(row),
            due_date: options.due_date || '',
            due_time: options.due_time || '',
            matched: options.matched || null,
            lookedUp: normalPhone(options.phone),
            lookupSeq: 0,

            addItem() { this.items.push(row({})); },
            product(item) { return this.products.find((p) => p.id === Number(item.product_id)) || null; },
            variantsOf(item) { return this.product(item)?.variants || []; },
            unitPrice(item) {
                if (item.price !== '' && item.price !== null) return Number(item.price);
                const p = this.product(item);
                if (!p) return null;
                const v = p.variants.find((o) => o.id === Number(item.variant_id));
                return v ? v.price : p.price;
            },
            money(n) { return '৳' + Number(n || 0).toLocaleString('en-BD', { maximumFractionDigits: 0 }); },

            lookup() {
                const phone = normalPhone(this.$refs.phone ? this.$refs.phone.value : '');
                if (phone === this.lookedUp) return;

                this.lookedUp = phone;
                const seq = ++this.lookupSeq;

                if (!/^01\d{9}$/.test(phone)) {
                    this.matched = null;
                    return;
                }

                fetch(options.customerSearchUrl + '?q=' + encodeURIComponent(phone), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                    .then((res) => (res.ok ? res.json() : null))
                    .then((body) => {
                        // The number has changed since this was asked.
                        if (seq !== this.lookupSeq || !body || !Array.isArray(body.customers)) return;
                        this.matched = body.customers.find((c) => c.phone === phone) || null;
                        if (this.matched && this.$refs.name && !this.$refs.name.value.trim()) {
                            this.$refs.name.value = this.matched.name || '';
                        }
                    })
                    // Advisory only: the reminder is matched to its customer on save anyway.
                    .catch(() => {});
            },
            customerLink(id) { return options.customerUrl ? options.customerUrl.replace('__ID__', id) : '#'; },

            // The shop's wall clock, read through Intl so a laptop set to
            // another timezone still offers Dhaka's evening and tomorrow.
            clock(date) {
                const parts = new Intl.DateTimeFormat('en-GB', {
                    timeZone: options.timezone, year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
                }).formatToParts(date);
                const get = (type) => (parts.find((p) => p.type === type) || {}).value;

                return { date: get('year') + '-' + get('month') + '-' + get('day'), time: get('hour') + ':' + get('minute'), hour: Number(get('hour')) };
            },
            dayAfter(ymd, days) {
                const [y, m, d] = ymd.split('-').map(Number);
                return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
            },
            eveningAhead() { return this.clock(new Date()).hour < 19; },
            preset(key) {
                const now = new Date();
                const today = this.clock(now).date;

                if (key === 'hour') {
                    // On the next five minutes: 4:35 PM, not 4:33 PM.
                    const at = this.clock(new Date(Math.ceil((now.getTime() + 3600000) / 300000) * 300000));
                    this.due_date = at.date;
                    this.due_time = at.time;
                } else if (key === 'evening') {
                    // A page left open past 7 PM still shows the button; the
                    // next evening, as a snooze does, never a time already gone.
                    this.due_date = this.eveningAhead() ? today : this.dayAfter(today, 1);
                    this.due_time = '19:00';
                } else if (key === 'tomorrow') {
                    this.due_date = this.dayAfter(today, 1);
                    this.due_time = '11:00';
                } else if (key === 'days3') {
                    this.due_date = this.dayAfter(today, 3);
                    this.due_time = '11:00';
                }
            },
            readout() {
                if (!/^\d{4}-\d{2}-\d{2}$/.test(this.due_date) || !/^\d{2}:\d{2}/.test(this.due_time)) return '';
                const [y, m, d] = this.due_date.split('-').map(Number);
                const [hh, mm] = this.due_time.split(':').map(Number);
                const day = new Date(Date.UTC(y, m - 1, d));

                return DAYS[day.getUTCDay()] + ' ' + d + ' ' + MONTHS[m - 1] + ', '
                    + (((hh + 11) % 12) + 1) + ':' + String(mm).padStart(2, '0') + ' ' + (hh < 12 ? 'AM' : 'PM');
            },
        };
    }
</script>
