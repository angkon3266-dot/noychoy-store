@extends('layouts.admin')
@section('title', 'Blocked numbers')
@section('heading', 'Blocked numbers')

@section('content')
{{-- Numbers that may not order (owner, 2026-09-18). Everything here is stored
     canonically, so a number blocked as "+880 1712-345678" also stops an order
     typed as 01712345678, 8801712345678 or 01712 345678. --}}
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('admin.customers.index') }}" class="btn-outline py-2 text-sm">← All customers</a>
    <p class="text-sm text-ink-700/60">A blocked number is refused at checkout, in the chat assistant and on orders typed here.</p>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="card p-5 h-fit">
        <h2 class="font-semibold mb-1">Block numbers</h2>
        <p class="text-xs text-ink-700/60 mb-3">One per line, or separated by commas. Any format —
            <span class="font-mono">01712345678</span>, <span class="font-mono">+880 1712-345678</span>,
            <span class="font-mono">8801712345678</span> — is the same number.</p>

        @if($errors->any())
            <div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mb-3">
                <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form action="{{ route('admin.customers.blocked.store') }}" method="POST" class="space-y-3">
            @csrf
            <textarea name="phones" rows="6" class="input font-mono text-xs"
                      placeholder="01712345678&#10;+880 1812-345678, 01912345678">{{ old('phones') }}</textarea>
            <input name="reason" value="{{ old('reason') }}" class="input" maxlength="200"
                   placeholder="Why (optional) — e.g. refused three parcels">
            <button class="btn-primary w-full">Block these numbers</button>
        </form>
    </div>

    <div class="lg:col-span-2 card overflow-hidden">
        <div class="flex flex-wrap items-center gap-2 p-4 border-b border-ink-100">
            <h2 class="font-semibold mr-auto">{{ number_format($blocked->total()) }} blocked</h2>
            <form method="GET" class="flex gap-2">
                <input name="q" value="{{ $q }}" placeholder="Number or reason…" class="input py-2 w-48">
                <button class="btn-outline py-2 text-sm">Search</button>
                @if($q)<a href="{{ route('admin.customers.blocked') }}" class="btn-outline py-2 text-sm">Clear</a>@endif
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                    <tr>
                        <th class="px-4 py-3">Number</th>
                        <th class="px-4 py-3">Why</th>
                        <th class="px-4 py-3">Before this</th>
                        <th class="px-4 py-3">Blocked</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @forelse($blocked as $row)
                        @php $past = $orders[$row->phone] ?? null; $customer = $customers[$row->phone] ?? null; @endphp
                        <tr class="hover:bg-ink-50">
                            <td class="px-4 py-3">
                                <div class="font-mono">{{ $row->phone }}</div>
                                @if($customer)
                                    <a href="{{ route('admin.customers.show', $customer->id) }}" class="text-xs text-gold-700 hover:underline">{{ $customer->name }}</a>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-ink-700/70">{{ $row->reason ?: '—' }}</td>
                            <td class="px-4 py-3 text-ink-700/70">
                                @if($past)
                                    {{ $past->orders }} order{{ $past->orders == 1 ? '' : 's' }}
                                    <span class="text-xs text-ink-700/50">· last {{ store_time($past->last_at)->format('d M Y') }}</span>
                                @else
                                    <span class="text-ink-700/40">never ordered</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-ink-700/60">
                                {{ store_time($row->created_at)->format('d M Y') }}
                                @if($row->blockedBy)<div>by {{ $row->blockedBy->name }}</div>@endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <form action="{{ route('admin.customers.blocked.destroy', $row) }}" method="POST"
                                      onsubmit="return confirm('Let {{ $row->phone }} order again?')">
                                    @csrf @method('DELETE')
                                    <button class="text-xs text-gold-700 hover:underline">Unblock</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-ink-700/50">
                            {{ $q ? 'No blocked number matches that.' : 'No numbers are blocked.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-6">{{ $blocked->links() }}</div>
@endsection
