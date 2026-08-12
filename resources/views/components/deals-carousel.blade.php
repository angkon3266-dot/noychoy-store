@props(['deals' => null])

@php
    use App\Support\DailyDeals;

    $cards = $deals ?? DailyDeals::cards();
    $endsAt = DailyDeals::endsAt();
@endphp

@if($cards->isNotEmpty())
{{-- The whole section sits inside the countdown component so it can take itself
     off the page the moment the deadline passes, without a reload. With no
     deadline set there is no countdown and nothing to hide. --}}
<section class="mx-auto max-w-7xl px-4 py-12 lg:py-16"
         @if($endsAt)
             x-data="countdownBox({{ $endsAt->getTimestamp() }})" x-init="start()" x-show="!expired"
         @endif>

    <div class="flex flex-wrap items-end justify-between gap-4 mb-8">
        <div>
            <p class="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-2">Today only</p>
            <h2 class="font-display text-3xl sm:text-4xl text-ink-900">{{ home_content('deals_title') ?: 'Deals of the Day' }}</h2>
            @if(home_content('deals_subtitle'))
                <p class="mt-2 text-ink-700/60">{{ home_content('deals_subtitle') }}</p>
            @endif
        </div>

        @if($endsAt)
            <div class="flex items-center gap-2">
                <span class="text-xs uppercase tracking-widest text-ink-700/50 mr-1">Ends in</span>
                <template x-for="u in units" :key="u.label">
                    <div class="rounded-lg bg-ink-900 text-white px-2.5 py-1.5 text-center w-12">
                        <div class="text-base font-semibold tabular-nums" x-text="String(u.value).padStart(2,'0')"></div>
                        <div class="text-[9px] uppercase tracking-widest text-white/50" x-text="u.label"></div>
                    </div>
                </template>
            </div>
        @endif
    </div>

    <x-carousel>
        @foreach($cards as $card)
            <a href="{{ $card['href'] }}"
               class="group snap-start shrink-0 w-[280px] rounded-2xl overflow-hidden border border-ink-100 bg-white flex flex-col hover:-translate-y-2 hover:shadow-lg transition duration-300">

                <div class="relative h-44 bg-gold-100 overflow-hidden shrink-0">
                    @if($card['image'])
                        <img src="{{ $card['image'] }}" alt="{{ $card['title'] }}" loading="lazy"
                             class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105">
                    @endif
                    @if($card['discount'])
                        <span class="absolute top-3 left-3 rounded-full bg-ink-900 text-white text-[11px] font-medium px-3 py-1">{{ $card['discount'] }}</span>
                    @endif
                </div>

                <div class="flex-1 p-5 flex flex-col justify-between gap-4">
                    <div class="space-y-2">
                        <p class="flex items-center gap-1.5 text-[11px] uppercase tracking-wider text-gold-700">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.878.53 2.31-.354A11.95 11.95 0 0021 15.75c0-.98-.117-1.933-.338-2.846M6.375 6.375h.008v.008H6.375V6.375z"/></svg>
                            {{ $card['tag'] }}
                        </p>
                        <h3 class="font-semibold text-ink-900 leading-snug">{{ $card['title'] }}</h3>
                        @if($card['description'])
                            <p class="text-sm text-ink-700/60 line-clamp-2">{{ $card['description'] }}</p>
                        @endif
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-ink-100">
                        <span class="text-xs tracking-wide text-ink-700/60">Shop this deal</span>
                        {{-- Rotates and fills on hover: the same "go" affordance the
                             category tiles use, so the card reads as clickable. --}}
                        <span class="w-8 h-8 rounded-full bg-ink-50 text-ink-900 grid place-items-center transition duration-300 group-hover:-rotate-45 group-hover:bg-ink-900 group-hover:text-white">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 12h16m0 0l-6-6m6 6l-6 6"/></svg>
                        </span>
                    </div>
                </div>
            </a>
        @endforeach
    </x-carousel>
</section>
@endif
