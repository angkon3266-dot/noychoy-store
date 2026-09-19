{{-- A product's small picture in an admin report or alert (App\Support\ProductThumbs).
     An empty tile keeps the names lined up when a product has no photo. --}}
@props(['src' => null])
<span {{ $attributes->merge(['class' => 'inline-block h-8 w-8 shrink-0 overflow-hidden rounded bg-ink-50 align-middle']) }}>
    @if($src)<img src="{{ $src }}" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover">@endif
</span>
