@props(['product', 'loved' => false, 'count' => 0])
<div x-data="{ loved: @js((bool) $loved), count: {{ (int) $count }}, busy: false,
        toggle(){
            if (this.busy) return; this.busy = true;
            fetch('{{ route('product.love', $product) }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
            }).then(r => r.json()).then(d => { this.loved = d.loved; this.count = d.count; })
              .finally(() => this.busy = false);
        } }"
     class="mt-3 flex items-center gap-2">
    <button type="button" @click="toggle" :disabled="busy"
        class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition"
        :class="loved ? 'border-red-300 bg-red-50 text-red-600' : 'border-ink-200 text-ink-700 hover:border-red-300 hover:text-red-500'">
        <svg class="w-4 h-4" :fill="loved ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
        <span x-text="loved ? 'Loved' : 'Love'"></span>
    </button>
    <span class="text-sm text-ink-700/60" x-show="count > 0" x-cloak>
        <span x-text="count"></span> <span x-text="count == 1 ? 'person' : 'people'"></span> loved this
    </span>
</div>
