{{-- The owner's pinned message on the Blade storefront pages (the non-React
     homepage templates and landing pages) — the same line that
     resources/js/Shared/Chrome/PinnedMessage.jsx draws on the React ones.
     Included inside the sticky header, so it floats with it. Hides itself at
     its end time for a page the LiteSpeed cache kept past it. --}}
@php $pinned = \App\Support\PinnedMessage::current(); @endphp
@if($pinned)
    <div x-data="{
            show: true,
            init() {
                const end = @js($pinned['until']) ? Date.parse(@js($pinned['until'])) : NaN;
                if (!isNaN(end)) {
                    const left = end - Date.now();
                    if (left <= 0) this.show = false;
                    else setTimeout(() => this.show = false, Math.min(left, 2147483647));
                }
                @if($pinned['dismissible'])
                    try { if (localStorage.getItem('pinned-dismissed') === @js($pinned['id'])) this.show = false; } catch (e) {}
                @endif
            },
            dismiss() {
                this.show = false;
                try { localStorage.setItem('pinned-dismissed', @js($pinned['id'])); } catch (e) {}
            },
         }"
         x-show="show"
         class="relative text-center text-[13px] sm:text-sm leading-snug"
         style="background: {{ $pinned['bg'] }}; color: {{ $pinned['color'] }}"
         data-pinned-message>
        <div class="mx-auto max-w-7xl py-2 {{ $pinned['dismissible'] ? 'pl-4 pr-10' : 'px-4' }}">
            @if($pinned['link'])
                <a href="{{ $pinned['link'] }}" class="inline-flex flex-wrap items-baseline justify-center gap-x-2 gap-y-0.5 hover:opacity-90">
                    <span class="font-semibold [overflow-wrap:anywhere]">{{ $pinned['text'] }}</span>
                    @if($pinned['linkLabel'])<span class="whitespace-nowrap font-medium underline underline-offset-2">{{ $pinned['linkLabel'] }} →</span>@endif
                </a>
            @else
                <span class="font-semibold [overflow-wrap:anywhere]">{{ $pinned['text'] }}</span>
            @endif
        </div>
        @if($pinned['dismissible'])
            <button type="button" @click="dismiss()" aria-label="Close this message"
                    class="absolute right-1.5 top-1/2 -translate-y-1/2 px-2 py-1 text-base leading-none opacity-70 hover:opacity-100">×</button>
        @endif
    </div>
@endif
