@extends('layouts.admin')
@section('title', 'Member offers')
@section('heading', 'Member offers')

@section('content')
<p class="text-sm text-ink-700/60 mb-4 max-w-3xl">
    Every personalized offer you've given to individual members. Create them from a customer's page
    (<a href="{{ route('admin.customers.index') }}" class="text-gold-700 underline">Customers</a> → open a customer → Personalised offers),
    or apply one to many at once from the customer list.
</p>

<div class="flex flex-wrap gap-1 mb-4 text-sm">
    @foreach(['live'=>'Live','all'=>'All','redeemed'=>'Redeemed','expired'=>'Expired'] as $k => $lbl)
        <a href="{{ route('admin.customers.all-offers', ['status'=>$k]) }}"
           class="px-3 py-1.5 rounded-lg {{ $status===$k ? 'bg-ink-900 text-white' : 'text-ink-700 hover:bg-ink-100' }}">{{ $lbl }}</a>
    @endforeach
</div>

<div class="card overflow-x-auto">
    <table class="w-full min-w-[760px] text-sm">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr>
                <th class="px-4 py-3">Customer</th>
                <th class="px-4 py-3">Offer</th>
                <th class="px-4 py-3">Reward</th>
                <th class="px-4 py-3">Applies to</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Expires</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($offers as $offer)
                <tr class="hover:bg-ink-50">
                    <td class="px-4 py-3">
                        @if($offer->customer)
                            <a href="{{ route('admin.customers.show', $offer->customer) }}" class="text-gold-700 hover:underline">{{ $offer->customer->name }}</a>
                            <div class="text-xs text-ink-700/50">{{ $offer->customer->phone }}</div>
                        @else <span class="text-ink-700/40">—</span> @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ $offer->title }}</div>
                        @if($offer->message)<div class="text-xs text-ink-700/50 italic truncate max-w-[240px]">“{{ $offer->message }}”</div>@endif
                        @if($offer->code)<div class="text-xs font-mono text-gold-700">{{ $offer->code }}</div>@endif
                    </td>
                    <td class="px-4 py-3">{{ $offer->rewardText() }}</td>
                    <td class="px-4 py-3 text-xs text-ink-700/70">
                        {{ $offer->scopeLabel() }}@if($offer->applies_to==='categories' && $offer->category_ids) · {{ count($offer->category_ids) }} categor{{ count($offer->category_ids)===1?'y':'ies' }}@elseif($offer->applies_to==='products' && $offer->product_ids) · {{ count($offer->product_ids) }} product(s)@endif
                    </td>
                    <td class="px-4 py-3">
                        @if($offer->isLive())<span class="badge bg-green-100 text-green-700">Live</span>
                        @elseif($offer->redeemed_at)<span class="badge bg-ink-100 text-ink-700">Used up</span>
                        @else<span class="badge bg-amber-100 text-amber-700">Inactive</span>@endif
                        <div class="text-[11px] text-ink-700/50 mt-0.5">{{ $offer->usageLabel() }}</div>
                    </td>
                    <td class="px-4 py-3 text-xs text-ink-700/60">{{ $offer->expires_at?->format('d M Y') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-ink-700/50">No offers in this view.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $offers->links() }}</div>
@endsection
