{{-- A settings card that folds down to its title and a one-line status.

     The Offers page stacks four of these above the offer list, and open they
     ran several screens before the list itself (owner, 2026-09-22), so they
     start folded. One opens by itself when its own form has just been saved
     or turned down (`open`), or when the address ends in #<id> — how a link
     from another screen lands on the right card. --}}
@props(['id', 'title', 'open' => false])
<section id="{{ $id }}" {{ $attributes->merge(['class' => 'card']) }}
         x-data="{ open: @js((bool) $open) || window.location.hash === @js('#'.$id) }"
         @hashchange.window="if (window.location.hash === @js('#'.$id)) open = true">
    <h2>
        <button type="button" @click="open = !open" :aria-expanded="open" aria-controls="{{ $id }}-body"
                class="flex w-full items-center gap-3 px-5 py-4 text-left">
            <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                <span class="font-semibold">{{ $title }}</span>
                {{ $status ?? '' }}
            </span>
            <svg class="h-4 w-4 shrink-0 text-ink-700/50 transition" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
    </h2>
    <div id="{{ $id }}-body" x-show="open" x-cloak x-collapse>
        <div class="px-5 pb-5">{{ $slot }}</div>
    </div>
</section>
