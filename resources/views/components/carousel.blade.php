@props([])
<div x-data="{ scroll(d){ $refs.track.scrollBy({ left: d * $refs.track.clientWidth * 0.85, behavior: 'smooth' }) } }"
     {{ $attributes->merge(['class' => 'relative']) }}>
    <button type="button" @click="scroll(-1)"
            class="hidden md:grid place-items-center absolute left-0 top-1/2 -translate-y-1/2 z-10 w-9 h-9 rounded-full bg-white shadow border border-ink-100 text-lg hover:bg-ink-50">‹</button>

    <div x-ref="track" class="flex gap-4 overflow-x-auto snap-x scroll-smooth pb-2" style="scrollbar-width:none;-ms-overflow-style:none;-webkit-overflow-scrolling:touch">
        {{ $slot }}
    </div>

    <button type="button" @click="scroll(1)"
            class="hidden md:grid place-items-center absolute right-0 top-1/2 -translate-y-1/2 z-10 w-9 h-9 rounded-full bg-white shadow border border-ink-100 text-lg hover:bg-ink-50">›</button>
</div>
