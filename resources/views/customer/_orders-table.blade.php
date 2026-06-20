@if($orders->isEmpty())
    <div class="card p-8 text-center text-ink-700/60">No orders yet. <a href="{{ route('shop') }}" class="text-gold-700 hover:underline">Start shopping</a></div>
@else
    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gold-100/60 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Order</th><th class="px-4 py-3">Date</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Status</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @foreach($orders as $order)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $order->order_number }}</td>
                        <td class="px-4 py-3 text-ink-700/70">{{ $order->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3">{{ money($order->total) }}</td>
                        <td class="px-4 py-3"><span class="badge bg-gold-100 text-gold-800 capitalize">{{ $order->status }}</span></td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('account.order', $order->order_number) }}" class="text-gold-700 hover:underline">View</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if(method_exists($orders, 'links'))<div class="mt-6">{{ $orders->links() }}</div>@endif
@endif
