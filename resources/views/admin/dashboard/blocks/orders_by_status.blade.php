{{-- Order status breakdown: two columns on a phone rather than nine rows,
     back to one list beside the chart from lg. --}}
<div class="card p-3 sm:p-5">
    <h2 class="font-semibold text-sm sm:text-base mb-2 sm:mb-4">Orders by status</h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-1 gap-x-4 gap-y-1.5 lg:gap-y-2">
        @foreach(\App\Models\Order::STATUSES as $key => $label)
            <div class="flex min-w-0 items-center justify-between gap-2 text-xs sm:text-sm">
                <span class="truncate text-ink-700/70" title="{{ $label }}">{{ $label }}</span>
                <span class="badge bg-ink-100 text-ink-700 tabular-nums">{{ $statusCounts[$key] ?? 0 }}</span>
            </div>
        @endforeach
    </div>
</div>
