@isset($tracking)
@if($tracking)
    <div class="mt-6 border-t border-ink-100 pt-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-medium">Courier tracking</h2>
            <span class="badge {{ $tracking['tone_class'] }}">{{ $tracking['label'] }}</span>
        </div>

        {{-- Progress bar --}}
        <div class="flex items-center">
            @foreach(['Booked', 'Picked up', 'In transit', 'Delivered'] as $i => $s)
                <div class="flex items-center {{ $loop->last ? '' : 'flex-1' }}">
                    <div class="flex flex-col items-center">
                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs {{ $i <= $tracking['step'] ? 'bg-gold-600 text-white' : 'bg-ink-100 text-ink-400' }}">
                            @if($i < $tracking['step'])
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            @else
                                {{ $i + 1 }}
                            @endif
                        </div>
                        <span class="mt-1 text-[10px] text-center {{ $i <= $tracking['step'] ? 'text-ink-800' : 'text-ink-400' }}">{{ $s }}</span>
                    </div>
                    @unless($loop->last)
                        <div class="flex-1 h-0.5 mx-1 -mt-4 {{ $i < $tracking['step'] ? 'bg-gold-600' : 'bg-ink-100' }}"></div>
                    @endunless
                </div>
            @endforeach
        </div>

        @if($tracking['tracking_code'])
            <p class="mt-4 text-sm text-ink-700/70">Tracking code: <strong class="text-ink-900">{{ $tracking['tracking_code'] }}</strong> · via Steadfast</p>
        @endif
    </div>
@endif
@endisset
