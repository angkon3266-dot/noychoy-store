@props([
    'token' => null,        // section_order token; null for the fixed panels (page settings, hero)
    'title',
    'hint' => null,
    'toggle' => null,       // home_content key of the section's on/off switch
    'home' => [],
    'fixed' => false,       // not draggable (the hero always opens the page)
    'open' => false,
])
{{--
    One section of the homepage builder (Admin → Appearance → Homepage).
    Draggable rows carry data-section-row; their order is the order of the
    hidden section_order[] inputs the form posts (see homeSections in app.js).
--}}
<div @unless($fixed) data-section-row @endunless x-data="{ open: @js((bool) $open) }"
     {{ $attributes->merge(['class' => 'rounded-xl border border-ink-100 bg-white shadow-sm']) }}>
    @if($token)<input type="hidden" name="section_order[]" value="{{ $token }}">@endif
    <div class="flex items-center gap-2 px-3 py-2.5">
        @unless($fixed)
            <span data-section-grip class="shrink-0 cursor-grab active:cursor-grabbing touch-none select-none px-1 text-lg leading-none text-ink-700/40 hover:text-ink-900" title="Drag to reorder">⠿</span>
            <div class="flex flex-col shrink-0">
                <button type="button" data-section-up @click="move($el.closest('[data-section-row]'), -1)" class="w-6 h-5 rounded text-[11px] text-ink-700/60 hover:bg-ink-100 hover:text-ink-900 leading-none disabled:opacity-30" title="Move up">▲</button>
                <button type="button" data-section-down @click="move($el.closest('[data-section-row]'), 1)" class="w-6 h-5 rounded text-[11px] text-ink-700/60 hover:bg-ink-100 hover:text-ink-900 leading-none disabled:opacity-30" title="Move down">▼</button>
            </div>
            <span data-section-num class="shrink-0 w-6 text-xs tabular-nums text-ink-700/40"></span>
        @else
            <span class="shrink-0 w-6 text-center text-ink-700/40" title="Always first">★</span>
        @endunless
        <button type="button" @click="open = !open" class="flex-1 min-w-0 text-left">
            <span class="block text-sm font-semibold text-ink-900">{{ $title }}</span>
            @if($hint)<span class="block text-xs text-ink-700/55 truncate">{{ $hint }}</span>@endif
        </button>
        @if($toggle)
            <input type="hidden" name="home_{{ $toggle }}_present" value="1">
            <label class="shrink-0 inline-flex items-center gap-2 cursor-pointer select-none" title="Show this section on the homepage">
                <input type="checkbox" name="home_{{ $toggle }}" value="1" @checked($home[$toggle] ?? true) class="peer sr-only">
                <span class="relative w-9 h-5 rounded-full bg-ink-200 transition-colors peer-checked:bg-gold-600 after:absolute after:top-0.5 after:left-0.5 after:w-4 after:h-4 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:after:translate-x-4"></span>
                <span class="text-xs text-ink-700/60 w-7">Show</span>
            </label>
        @endif
        <button type="button" @click="open = !open" class="shrink-0 grid place-items-center w-8 h-8 rounded-lg hover:bg-ink-50 text-ink-700/60" :aria-expanded="open" aria-label="Edit {{ $title }}">
            <svg class="w-4 h-4 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
    </div>
    <div x-show="open" x-cloak class="border-t border-ink-100 p-4 space-y-4">
        {{ $slot }}
    </div>
</div>
