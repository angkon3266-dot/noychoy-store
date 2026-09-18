{{-- Recent orders. On a phone the five-column table did not fit 343px, so
     below sm the date tucks under the order number and the status under the
     total — two dense lines a row, the same cells, no second copy of the
     list — and only the latest five show until "Show all". --}}
<div class="card overflow-hidden group" x-data="{ all: false }" :data-all="all">
    <div class="flex items-center justify-between px-3 sm:px-5 py-2.5 sm:py-4 border-b border-ink-100">
        <h2 class="font-semibold text-sm sm:text-base">Recent orders</h2>
        <a href="{{ route('admin.orders.index') }}" class="text-xs sm:text-sm text-gold-700 hover:underline">All orders →</a>
    </div>
    <table class="w-full text-[13px] sm:text-sm">
        <thead class="hidden sm:table-header-group bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr><th class="px-5 py-3">Order</th><th class="px-5 py-3">Customer</th><th class="px-5 py-3">Total</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Date</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($recentOrders as $order)
                <tr class="{{ $loop->index >= 5 ? 'hidden md:table-row group-data-[all]:table-row' : '' }} hover:bg-ink-50">
                    <td class="pl-3 pr-2 py-2 sm:px-5 sm:py-3 align-top sm:align-middle">
                        <a href="{{ route('admin.orders.show', $order) }}" class="font-medium text-gold-700 hover:underline">{{ $order->order_number }}</a>
                        <div class="sm:hidden text-[11px] whitespace-nowrap text-ink-700/50">{{ $order->created_at->diffForHumans(null, null, true) }}</div>
                    </td>
                    <td class="px-2 py-2 sm:px-5 sm:py-3 align-top sm:align-middle">{{ $order->customer_name }}<div class="text-[11px] sm:text-xs text-ink-700/50">{{ $order->customer_phone }}</div></td>
                    <td class="pl-2 pr-3 py-2 sm:px-5 sm:py-3 align-top sm:align-middle text-right sm:text-left whitespace-nowrap tabular-nums">{{ money($order->total) }}
                        <div class="sm:hidden mt-0.5"><span class="badge px-2 text-[10px] bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span></div>
                    </td>
                    <td class="hidden sm:table-cell px-5 py-3"><span class="badge bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span></td>
                    <td class="hidden sm:table-cell px-5 py-3 text-ink-700/60">{{ $order->created_at->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-5 py-8 text-center text-ink-700/50">No orders yet.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($recentOrders->count() > 5)
        <button type="button" @click="all = !all" class="md:hidden w-full border-t border-ink-100 py-2 text-xs font-medium text-gold-700"
                x-text="all ? 'Show fewer' : 'Show all {{ $recentOrders->count() }}'">Show all {{ $recentOrders->count() }}</button>
    @endif
</div>
