{{-- One row per wallet movement. $showPhone adds the number column for the
     all-wallets list; a single wallet's page leaves it out. --}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr>
                <th class="px-4 py-3">When</th>
                @if($showPhone ?? false)<th class="px-4 py-3">Number</th>@endif
                <th class="px-4 py-3">What</th>
                <th class="px-4 py-3 text-right">Amount</th>
                <th class="px-4 py-3 text-right">Balance after</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($transactions as $tx)
                <tr>
                    <td class="px-4 py-3 text-xs text-ink-700/60 whitespace-nowrap">{{ store_time($tx->created_at)->format('d M Y, g:i a') }}</td>
                    @if($showPhone ?? false)
                        <td class="px-4 py-3 font-mono text-xs">
                            @if($tx->wallet)<a href="{{ route('admin.customers.wallets.show', $tx->wallet_id) }}" class="hover:underline">{{ $tx->wallet->phone }}</a>@endif
                        </td>
                    @endif
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ $tx->typeLabel() }}</div>
                        <div class="text-xs text-ink-700/60">
                            @if($tx->order)
                                <a href="{{ route('admin.orders.show', $tx->order_id) }}" class="text-gold-700 hover:underline">{{ $tx->order->order_number }}</a>
                            @endif
                            {{ $tx->order ? '' : $tx->note }}
                            @if($tx->user)<span class="text-ink-700/40">· by {{ $tx->user->name }}</span>@endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold {{ (float) $tx->amount >= 0 ? 'text-green-700' : 'text-red-600' }}">
                        {{ (float) $tx->amount >= 0 ? '+' : '−' }}{{ money(abs((float) $tx->amount)) }}
                    </td>
                    <td class="px-4 py-3 text-right text-ink-700/70">{{ money($tx->balance_after) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ ($showPhone ?? false) ? 5 : 4 }}" class="px-4 py-10 text-center text-ink-700/50">Nothing has moved yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
