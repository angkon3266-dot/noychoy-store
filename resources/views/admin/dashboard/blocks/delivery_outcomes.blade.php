@if($deep['operations'])
    @php $o = $deep['operations']; @endphp
    {{-- The two three-line lists become three cells across on a phone and
         go back to label-left, figure-right lists from lg, where the card
         is a third of the page wide. Same markup for both. --}}
    <div class="card p-3 sm:p-5">
        <h2 class="font-semibold text-sm sm:text-base mb-1 sm:mb-3">Delivery outcomes</h2>
        <div class="flex items-baseline gap-2 lg:block">
            <div class="text-2xl sm:text-3xl font-semibold tabular-nums text-green-700">{{ $o['cod_success'] === null ? '—' : $o['cod_success'].'%' }}</div>
            <p class="text-[11px] sm:text-xs text-ink-700/50 lg:mb-3">COD success, {{ $per }}</p>
        </div>
        <div class="mt-2 lg:mt-0 grid grid-cols-3 lg:grid-cols-1 gap-1.5 lg:gap-1 text-center lg:text-left text-sm">
            @foreach([
                ['Delivered', $o['delivered'], ''],
                ['Cancelled', $o['cancelled'], 'text-amber-600'],
                ['Returned', $o['returned'], 'text-red-600'],
            ] as [$outcome, $outcomeCount, $outcomeClass])
                <div class="rounded-md bg-ink-50 px-1 py-1 lg:flex lg:justify-between lg:bg-transparent lg:p-0">
                    <span class="block text-[11px] text-ink-700/60 lg:inline lg:text-sm lg:text-current">{{ $outcome }}</span>
                    <span class="font-medium tabular-nums {{ $outcomeClass }}">{{ $outcomeCount }}</span>
                </div>
            @endforeach
        </div>
        <h3 class="text-xs sm:text-sm font-semibold mt-3 lg:mt-4 mb-1">Unfulfilled orders</h3>
        <div class="grid grid-cols-3 lg:grid-cols-1 gap-1.5 lg:gap-1 text-center lg:text-left text-sm">
            @foreach([
                ['Today', $o['pending_aging']['today'], ''],
                ['1–3 days old', $o['pending_aging']['1_3'], 'text-amber-600'],
                ['Over 3 days', $o['pending_aging']['over_3'], 'text-red-600 font-medium'],
            ] as [$age, $ageCount, $ageClass])
                <div class="rounded-md bg-ink-50 px-1 py-1 lg:flex lg:justify-between lg:bg-transparent lg:p-0">
                    <span class="block text-[11px] text-ink-700/60 lg:inline lg:text-sm lg:text-current">{{ $age }}</span>
                    <span class="tabular-nums {{ $ageClass }}">{{ $ageCount }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endif
