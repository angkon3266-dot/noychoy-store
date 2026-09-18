@extends('layouts.admin')
@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')
{{-- Owner's call, 17 Sep 2026: the dashboard is mostly read on a phone, and it
     took a long scroll — or a pinch to zoom out — to get past the first few
     cards. The page is compacted for a ~375px screen rather than cut down:
     every figure is still on it. Tighter padding and type below sm; long lists
     show their first few rows with a "Show all" button; secondary panels fold
     shut below md and open on a tap. From md up everything is open as before.

     Folding and "Show all" are a data attribute on a Tailwind `group`, never
     x-show: x-show writes an inline display:none, which beats `md:block`, so a
     panel left closed on a phone would also stay closed on a desktop. Before
     Alpine starts the attribute is simply absent, so a phone renders folded
     and a desktop renders open with no flash either way.

     Owner's call, 18 Sep 2026: "I need to be able to move around the
     analytics, top, bottom as per my needs." Every card is now a block from
     App\Support\DashboardBlocks — one partial each under dashboard/blocks/ —
     rendered in THIS admin's saved order (App\Support\DashboardLayout) inside
     a single grid, so the page no longer knows which card is which. "Arrange"
     (the ⚙) puts a handle above every block with up / down / top / bottom /
     hide, which work on a phone; on a computer the handle also drags. A move
     is the <section> moving in the DOM, never a re-render: the sales chart,
     the funnel chart and the live-visitors poll are Alpine components that
     keep their state and timers through insertBefore and would lose both if
     the node were cloned. A hidden block is neither computed nor rendered —
     it leaves a title-only placeholder that only arrange mode shows, so it
     can be put back where it was. Without JavaScript there is no arrange
     mode and the page is simply the page in the saved order. --}}
<div x-data="dashboardArrange(@js([
        'order' => $layout['order'],
        'hidden' => $layout['hidden'],
        'save' => route('admin.dashboard.layout'),
        'reset' => route('admin.dashboard.layout.reset'),
        'csrf' => csrf_token(),
     ]))"
     @keydown.escape.window="arranging && cancel()">

{{-- Reporting window. Every time-based figure below follows this; live counts
     (to process, stock, customer base) deliberately do not.

     On a phone the presets are one strip that scrolls sideways instead of four
     wrapped rows, scrolled so the chosen one is in view. ⚙ sits in the same row
     but outside the strip so nothing clips it. Neither is a block: the window
     and the arrange switch apply to every block, so they stay put. --}}
<div class="mb-3 sm:mb-4" x-data="{ custom: @js($range->isCustom()) }">
    <div class="flex items-start gap-2">
        <div class="relative min-w-0 flex-1 flex gap-1.5 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden sm:flex-wrap sm:gap-2 sm:overflow-visible"
             x-init="$nextTick(() => { const a = $el.querySelector('[data-current]'); if (a && $el.scrollWidth > $el.clientWidth) $el.scrollLeft = a.offsetLeft - ($el.clientWidth - a.offsetWidth) / 2 })">
            @foreach(\App\Support\DateRange::PRESETS as $key => $label)
                <a href="{{ route('admin.dashboard', ['period' => $key]) }}"
                   @if($range->key === $key) aria-current="page" data-current @endif
                   class="shrink-0 whitespace-nowrap rounded-lg px-2.5 sm:px-3 py-1.5 text-xs font-medium border transition
                          {{ $range->key === $key
                                ? 'bg-ink-900 text-white border-ink-900'
                                : 'bg-white text-ink-700/70 border-ink-100 hover:border-ink-300' }}">{{ $label }}</a>
            @endforeach

            <button type="button" @click="custom = !custom" :aria-expanded="custom"
                    @if($range->isCustom()) data-current @endif
                    class="shrink-0 whitespace-nowrap rounded-lg px-2.5 sm:px-3 py-1.5 text-xs font-medium border transition
                           {{ $range->isCustom()
                                ? 'bg-ink-900 text-white border-ink-900'
                                : 'bg-white text-ink-700/70 border-ink-100 hover:border-ink-300' }}">
                {{ $range->isCustom() ? $range->label : 'Custom…' }}
            </button>
        </div>

        {{-- Arrange mode on and off. Just the ⚙ on a phone; the word stays for
             screen readers and comes back from sm. Hidden without JavaScript
             (sb-needs-js, see the admin layout) since it would do nothing. --}}
        <button type="button" @click="arranging ? cancel() : start()" :aria-pressed="arranging"
                aria-label="Arrange dashboard" title="Arrange dashboard"
                class="sb-needs-js btn-outline shrink-0 py-1.5 px-2.5 sm:px-3 text-xs" :class="arranging && 'bg-gold-100'">⚙<span class="sr-only sm:not-sr-only">Arrange</span></button>
    </div>

    <form x-show="custom" x-cloak method="GET" action="{{ route('admin.dashboard') }}"
          class="mt-2 flex flex-wrap items-center gap-2">
        <input type="hidden" name="period" value="custom">
        <input type="date" name="from" value="{{ $range->isCustom() ? $range->start->toDateString() : '' }}"
               class="input py-1 text-xs min-w-0 flex-1 sm:flex-none sm:w-auto" required>
        <span class="text-xs text-ink-700/40">to</span>
        <input type="date" name="to" value="{{ $range->isCustom() ? $range->end->toDateString() : '' }}"
               class="input py-1 text-xs min-w-0 flex-1 sm:flex-none sm:w-auto" required>
        <button class="btn-primary py-1.5 px-3 text-xs">Apply</button>
    </form>
</div>

@php
    // Lowercased to sit as a caption under the number — except a custom
    // range, whose label is a pair of dates ("10 Jul 2026 – 20 Jul 2026")
    // that strtolower would turn into "10 jul 2026". Several blocks read it
    // (the KPI tiles, sources, ads, delivery outcomes), so it is computed
    // once here and travels into every partial with the rest of the page's
    // variables.
    $per = $range->isAllTime()
        ? 'all time'
        : ($range->isCustom() ? $range->label : strtolower($range->label));
@endphp

{{-- The blocks. One grid for the whole page: six columns from md, twelve from
     lg, and a block's span (DashboardBlocks::spanClass) is the only layout it
     carries, so any order the admin saves lays out without a wrapper knowing
     what sits beside what. DOM order IS the order — the arrange buttons move
     these <section>s and the save posts the keys as they then stand.

     grid-cols-1, not a bare `grid`: a bare grid's one implicit column is
     sized to its widest card's min-content, and thirty no-wrap date labels
     under the sales bars made that ~900px — the whole page scrolled sideways
     on a phone. grid-cols-1 is minmax(0, 1fr), which can shrink to the screen. --}}
<div class="grid grid-cols-1 lg:grid-cols-12 gap-3 md:gap-6" x-ref="grid">
    @foreach($layout['order'] as $key)
        @php
            $block = \App\Support\DashboardBlocks::find($key);
            $isHidden = in_array($key, $layout['hidden'], true);

            // Rendered to a string first so a block with nothing to show — no
            // unread messages, or an analytics call that failed and was
            // reported — costs no grid row: an empty <section> would still
            // take the gap on either side of it. Such a block, like a hidden
            // one, is a title-only placeholder that only arrange mode shows.
            $failed = ! $isHidden && collect(\App\Support\DashboardBlocks::needsOf([$key]))->contains(fn ($n) => ($deep[$n] ?? null) === false);
            $body = $isHidden ? '' : ($failed
                ? '<div class="card p-3 sm:p-5"><h2 class="font-semibold text-sm sm:text-base">'.e($block['title']).'</h2><p class="mt-1 text-[13px] text-ink-700/60">Couldn’t be computed right now — see the log.</p></div>'
                : trim($__env->make(
                'admin.dashboard.blocks.'.$key,
                \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path'])
            )->render()));
            $placeholder = $isHidden || $body === '';
        @endphp
        <section data-block="{{ $key }}" data-span="{{ $block['span'] }}"
                 @if($isHidden) data-hidden @elseif($placeholder) data-empty @endif
                 class="min-w-0 {{ \App\Support\DashboardBlocks::spanClass($block['span']) }}{{ $placeholder ? ' hidden' : '' }}"
                 :class="{ hidden: {{ $placeholder ? 'true' : 'false' }} && !arranging, 'opacity-60': arranging && isHidden('{{ $key }}') }"
                 @dragover.prevent="over($event, '{{ $key }}')" @drop.prevent="dropped()">
            {{-- The handle: the block's name, the move buttons, hide or show.
                 Buttons first because the owner is on a phone; the bar itself
                 drags on a computer. --}}
            <div x-show="arranging" x-cloak draggable="true" @dragstart="drag('{{ $key }}', $event)" @dragend="dropped()"
                 class="mb-1.5 flex items-center gap-0.5 rounded-lg border border-ink-200 bg-ink-50 px-1.5 py-1 text-xs select-none cursor-move"
                 :class="isHidden('{{ $key }}') && 'border-dashed'">
                <span class="min-w-0 flex-1 truncate pl-1 font-medium">{{ $block['title'] }}
                    @if($isHidden)
                        <span class="font-normal text-ink-700/50" x-text="isHidden('{{ $key }}') ? '· hidden' : '· shows once you save'">· hidden</span>
                    @elseif($placeholder)
                        <span class="font-normal text-ink-700/50">· nothing to show right now</span>
                    @endif
                </span>
                <button type="button" @click="move('{{ $key }}', -1)" :disabled="isFirst('{{ $key }}')" aria-label="Move {{ $block['title'] }} up"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-ink-700/70 hover:bg-white hover:text-ink-900 disabled:opacity-40">▲</button>
                <button type="button" @click="move('{{ $key }}', 1)" :disabled="isLast('{{ $key }}')" aria-label="Move {{ $block['title'] }} down"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-ink-700/70 hover:bg-white hover:text-ink-900 disabled:opacity-40">▼</button>
                <button type="button" @click="toTop('{{ $key }}')" :disabled="isFirst('{{ $key }}')" aria-label="Move {{ $block['title'] }} to the top"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-ink-700/70 hover:bg-white hover:text-ink-900 disabled:opacity-40">⤒</button>
                <button type="button" @click="toBottom('{{ $key }}')" :disabled="isLast('{{ $key }}')" aria-label="Move {{ $block['title'] }} to the bottom"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-ink-700/70 hover:bg-white hover:text-ink-900 disabled:opacity-40">⤓</button>
                <button type="button" @click="toggle('{{ $key }}')" :aria-pressed="isHidden('{{ $key }}')"
                        aria-label="Hide {{ $block['title'] }}" :aria-label="isHidden('{{ $key }}') ? 'Show {{ $block['title'] }}' : 'Hide {{ $block['title'] }}'"
                        class="inline-flex h-7 shrink-0 items-center justify-center gap-1 rounded-md px-1.5 text-ink-700/70 hover:bg-white hover:text-ink-900 whitespace-nowrap"
                        x-text="isHidden('{{ $key }}') ? '👁 Show' : '👁 Hide'">👁 Hide</button>
            </div>
            @unless($placeholder)
                {{-- Collapsed to the handle while hidden in arrange mode, so
                     what the admin sees is what the next load will compute. --}}
                <div data-block-body :class="arranging && isHidden('{{ $key }}') && 'hidden'">{!! $body !!}</div>
            @endunless
        </section>
    @endforeach
</div>

{{-- The arrange bar. Sticky to the bottom of the screen while the page is
     being arranged, in normal flow once the page has been scrolled past it —
     inside <main> rather than fixed to the window, so it respects the sidebar. --}}
<div x-show="arranging" x-cloak
     class="sticky bottom-3 z-30 mt-3 md:mt-6 flex flex-wrap items-center gap-2 rounded-xl border border-ink-200 bg-white/95 p-2.5 shadow-xl backdrop-blur">
    {{-- The hint takes its own row on a phone: beside three buttons it was
         squeezed to one word a line. --}}
    <p class="w-full sm:w-auto sm:flex-1 min-w-0 text-xs text-ink-700/60">
        Move a block with its arrows<span class="hidden md:inline">, or drag it by its bar</span>. A hidden block stays here, greyed, until you show it again.
    </p>
    <div class="ml-auto flex items-center gap-2">
        <button type="button" @click="cancel()" class="btn-outline py-1.5 px-3 text-xs">Cancel</button>
        <button type="button" @click="reset()" :disabled="saving" class="btn-outline py-1.5 px-3 text-xs">Reset to default</button>
        <button type="button" @click="save()" :disabled="saving" class="btn-primary py-1.5 px-3 text-xs" x-text="saving ? 'Saving…' : 'Save layout'">Save layout</button>
    </div>
</div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    // Arrange mode for the dashboard blocks — see the note at the top of the
    // page. Registered here rather than in app.js so it ships with the page
    // and needs no `npm run build`; this script runs while the page parses,
    // before the bundle's deferred Alpine.start(), so the listener is in
    // place when Alpine boots.
    Alpine.data('dashboardArrange', (config) => ({
        arranging: false,
        saving: false,
        dragging: null,
        // Every block in DOM order, hidden ones included, and the hidden keys.
        order: [...config.order],
        hidden: [...config.hidden],

        start() {
            this.arranging = true;
        },

        // Cancel throws the unsaved moves away. The cheapest honest way to put
        // every block back is to reload — but only when something moved.
        cancel() {
            if (this.dirty()) {
                window.location.reload();

                return;
            }

            this.arranging = false;
        },

        dirty() {
            return this.order.join() !== config.order.join()
                || [...this.hidden].sort().join() !== [...config.hidden].sort().join();
        },

        isHidden(key) { return this.hidden.includes(key); },
        isFirst(key) { return this.order[0] === key; },
        isLast(key) { return this.order[this.order.length - 1] === key; },

        toggle(key) {
            this.hidden = this.isHidden(key) ? this.hidden.filter((k) => k !== key) : [...this.hidden, key];
        },

        section(key) {
            return this.$refs.grid.querySelector('[data-block="' + key + '"]');
        },

        move(key, by) { this.place(key, this.order.indexOf(key) + by, true); },
        toTop(key) { this.place(key, 0, true); },
        toBottom(key) { this.place(key, this.order.length - 1, true); },

        // The block is MOVED in the DOM, never rebuilt: the charts and the
        // live-visitors poll are Alpine components that keep their state and
        // timers through insertBefore, and Alpine treats a node that leaves
        // and re-enters in the same tick as a move, not a fresh mount.
        place(key, index, keepInView = false) {
            const from = this.order.indexOf(key);

            if (from === -1 || index < 0 || index >= this.order.length || index === from) return;

            this.order.splice(from, 1);
            this.order.splice(index, 0, key);

            const el = this.section(key);
            const next = this.order[index + 1];
            this.$refs.grid.insertBefore(el, next ? this.section(next) : null);

            // A tap on ⤓ sends the block a screen or more away on a phone;
            // follow it so the next tap lands on the same handle.
            if (keepInView) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        },

        // Computer only: the handle drags, and hovering the upper-left half
        // of another block drops before it, the lower-right half after it.
        drag(key, e) {
            this.dragging = key;
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', key); } catch (err) { /* older Edge */ }
        },

        over(e, key) {
            if (!this.dragging || this.dragging === key) return;

            const box = this.section(key).getBoundingClientRect();
            const before = (e.clientY - box.top) / box.height + (e.clientX - box.left) / box.width < 1;
            const from = this.order.indexOf(this.dragging);
            let index = this.order.indexOf(key);

            if (from < index) index -= 1;
            if (!before) index += 1;

            this.place(this.dragging, index);
        },

        dropped() {
            this.dragging = null;
        },

        async save() {
            await this.post(config.save, { order: this.order, hidden: this.hidden });
        },

        async reset() {
            if (!window.confirm('Put every block back in the default order and show them all?')) return;

            await this.post(config.reset, {});
        },

        async post(url, body) {
            this.saving = true;

            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': config.csrf,
                    },
                    body: JSON.stringify(body),
                    credentials: 'same-origin',
                });

                if (res.status === 419 || res.status === 401) {
                    window.alert('Your session expired — the page will reload.');
                    window.location.reload();
                    return;
                }
                if (!res.ok) throw new Error('HTTP ' + res.status);

                window.location.reload();
            } catch (err) {
                this.saving = false;
                window.alert('The layout could not be saved. Check the connection and try again.');
            }
        },
    }));
});
</script>
@endpush
