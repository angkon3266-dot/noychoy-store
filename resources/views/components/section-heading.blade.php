@props(['title', 'link' => null, 'linkLabel' => 'View All'])
<section class="mx-auto max-w-7xl px-4 pt-10 pb-5">
    <div class="relative text-center border-b border-ink-100 pb-3">
        <h2 class="inline-block font-display text-2xl sm:text-3xl tracking-wide text-ink-900">
            {{ $title }}
            <span class="block h-0.5 w-16 bg-gold-500 mt-2 mx-auto"></span>
        </h2>
        @if($link)
            <a href="{{ $link }}" class="absolute right-0 bottom-3 text-sm text-ink-700/70 hover:text-gold-700 whitespace-nowrap">{{ $linkLabel }}</a>
        @endif
    </div>
</section>
