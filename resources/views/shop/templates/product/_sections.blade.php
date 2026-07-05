{{-- Editorial "story sections": alternating image + heading + text blocks. --}}
@php $storySections = $product->content_sections ?? []; @endphp
@if(! empty($storySections))
    <section class="mt-12 space-y-12 sm:space-y-20">
        @foreach($storySections as $s)
            @php $imageLeft = ($s['layout'] ?? 'right') === 'left'; @endphp
            <div class="grid md:grid-cols-2 gap-6 md:gap-12 items-center">
                @if(! empty($s['image']))
                    <div class="{{ $imageLeft ? 'md:order-1' : 'md:order-2' }}">
                        <img src="{{ $s['image'] }}" alt="{{ $s['heading'] ?? '' }}" loading="lazy"
                             class="w-full rounded-xl object-cover shadow-sm">
                    </div>
                @endif
                <div class="{{ $imageLeft ? 'md:order-2' : 'md:order-1' }} {{ empty($s['image']) ? 'md:col-span-2 text-center max-w-2xl mx-auto' : '' }}">
                    @if(! empty($s['heading']))
                        <h2 class="font-display text-2xl sm:text-3xl font-semibold mb-3">{{ $s['heading'] }}</h2>
                    @endif
                    @if(! empty($s['body']))
                        <p class="text-ink-700/75 leading-relaxed whitespace-pre-line">{{ $s['body'] }}</p>
                    @endif
                </div>
            </div>
        @endforeach
    </section>
@endif
