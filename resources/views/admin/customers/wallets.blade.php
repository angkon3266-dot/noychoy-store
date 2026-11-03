@extends('layouts.admin')
@section('title', 'Wallets')
@section('heading', 'Wallets')

@section('content')
{{-- Wallet credit per mobile number (owner, 2026-11-03). Numbers are stored
     canonically, so credit added for "+880 1712-345678" is offered to a
     checkout typed as 01712345678. --}}
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('admin.customers.index') }}" class="btn-outline py-2 text-sm">← All customers</a>
    <p class="text-sm text-ink-700/60">Whoever checks out on the number can use its credit on products (not delivery). It comes off the total the rider collects, and goes back if the order is cancelled or returned.</p>
</div>

{{-- Credit just added: tell them on WhatsApp, message already written
     (owner, 2026-11-03). Opens WhatsApp; nothing is sent until she presses send. --}}
@if(($sent = session('wallet_whatsapp')) && ($sent['url'] ?? null))
    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm">
        <span class="text-green-800">Let {{ $sent['phone'] }} know about the credit:</span>
        <a href="{{ $sent['url'] }}" target="_blank" rel="noopener"
           class="inline-flex items-center gap-1.5 rounded-md bg-green-600 px-3 py-1.5 font-medium text-white hover:bg-green-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15-.2.3-.77.96-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.79-1.47-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.18.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.6-.92-2.2-.24-.58-.49-.5-.67-.5h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.75-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.04 21.5h-.01a9.45 9.45 0 0 1-4.82-1.32l-.35-.2-3.58.94.96-3.49-.23-.36a9.43 9.43 0 0 1-1.45-5.04c0-5.21 4.25-9.45 9.48-9.45a9.4 9.4 0 0 1 6.7 2.78 9.38 9.38 0 0 1 2.77 6.68c0 5.21-4.25 9.46-9.47 9.46zm8.06-17.52A11.32 11.32 0 0 0 12.04.66C5.76.66.65 5.76.65 12.04c0 2 .52 3.96 1.52 5.69L.55 23.34l5.73-1.5a11.36 11.36 0 0 0 5.75 1.47h.01c6.28 0 11.39-5.11 11.39-11.39 0-3.04-1.19-5.9-3.33-8.05z"/></svg>
            Message on WhatsApp
        </a>
    </div>
@endif

<div class="grid lg:grid-cols-3 gap-6">
    <div class="space-y-6">
        <div class="card p-5 h-fit">
            <h2 class="font-semibold mb-3">Add or take off money</h2>

            @if($errors->any())
                <div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mb-3">
                    <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <form action="{{ route('admin.customers.wallets.store') }}" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label class="label text-xs" for="w-phone">Mobile number</label>
                    <input id="w-phone" name="phone" value="{{ old('phone', $phone) }}" class="input font-mono" required
                           inputmode="numeric" placeholder="01XXXXXXXXX">
                </div>
                <div class="flex gap-2 text-sm">
                    <label class="flex-1 flex items-center gap-2 rounded-lg border border-ink-100 px-3 py-2 cursor-pointer has-[:checked]:border-green-500 has-[:checked]:bg-green-50">
                        <input type="radio" name="direction" value="add" @checked(old('direction', 'add') === 'add')> Add
                    </label>
                    <label class="flex-1 flex items-center gap-2 rounded-lg border border-ink-100 px-3 py-2 cursor-pointer has-[:checked]:border-red-400 has-[:checked]:bg-red-50">
                        <input type="radio" name="direction" value="take" @checked(old('direction') === 'take')> Take off
                    </label>
                </div>
                <div>
                    <label class="label text-xs" for="w-amount">Amount ৳</label>
                    <input id="w-amount" type="number" name="amount" value="{{ old('amount') }}" min="1" step="0.01" class="input" required>
                </div>
                <input name="note" value="{{ old('note') }}" class="input" maxlength="200"
                       placeholder="Why (optional) — e.g. refund for order NC-1234">
                <button class="btn-primary w-full">Save</button>
            </form>
        </div>

        {{-- The message every WhatsApp button on these pages fills in. --}}
        <details class="card p-5" @if($errors->has('template')) open @endif>
            <summary class="font-semibold cursor-pointer">WhatsApp message</summary>
            <form action="{{ route('admin.customers.wallets.template') }}" method="POST" class="space-y-2 mt-3">
                @csrf
                <textarea name="template" rows="7" maxlength="1000" class="input text-sm">{{ old('template', $template) }}</textarea>
                <p class="text-xs text-ink-700/60">
                    Fills in <code>{name}</code> <code>{amount}</code> (the credit added) <code>{balance}</code>
                    <code>{phone}</code> <code>{store}</code> <code>{link}</code>. Leave it empty to go back to the default.
                </p>
                <button class="btn-outline w-full text-sm">Save message</button>
            </form>
        </details>

        <div class="card p-5">
            <div class="text-xs text-ink-700/50">Credit held across all numbers</div>
            <div class="text-2xl font-semibold">{{ money($outstanding) }}</div>
        </div>
    </div>

    <div class="lg:col-span-2 space-y-6">
        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-2 p-4 border-b border-ink-100">
                <h2 class="font-semibold mr-auto">{{ number_format($wallets->total()) }} {{ $wallets->total() === 1 ? 'wallet' : 'wallets' }}</h2>
                <form method="GET" class="flex gap-2">
                    <input name="q" value="{{ $q }}" placeholder="Number or name…" class="input py-2 w-48">
                    <button class="btn-outline py-2 text-sm">Search</button>
                    @if($q)<a href="{{ route('admin.customers.wallets') }}" class="btn-outline py-2 text-sm">Clear</a>@endif
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                        <tr>
                            <th class="px-4 py-3">Number</th>
                            <th class="px-4 py-3 text-right">Balance</th>
                            <th class="px-4 py-3">Last change</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @forelse($wallets as $row)
                            @php $customer = $customers[$row->phone] ?? null; @endphp
                            <tr class="hover:bg-ink-50">
                                <td class="px-4 py-3">
                                    <div class="font-mono">{{ $row->phone }}</div>
                                    @if($customer)
                                        <a href="{{ route('admin.customers.show', $customer->id) }}" class="text-xs text-gold-700 hover:underline">{{ $customer->name }}</a>
                                    @else
                                        <span class="text-xs text-ink-700/40">no orders yet</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-semibold {{ (float) $row->balance > 0 ? 'text-green-700' : 'text-ink-700/50' }}">{{ money($row->balance) }}</td>
                                <td class="px-4 py-3 text-xs text-ink-700/60">{{ store_time($row->updated_at)->format('d M Y, g:i a') }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if($wa = $whatsapp[$row->id] ?? null)
                                        <a href="{{ $wa }}" target="_blank" rel="noopener" title="Message {{ $row->phone }} on WhatsApp"
                                           class="inline-flex items-center gap-1 text-xs text-green-700 hover:underline mr-3"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15-.2.3-.77.96-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.79-1.47-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.18.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.6-.92-2.2-.24-.58-.49-.5-.67-.5h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.75-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.04 21.5h-.01a9.45 9.45 0 0 1-4.82-1.32l-.35-.2-3.58.94.96-3.49-.23-.36a9.43 9.43 0 0 1-1.45-5.04c0-5.21 4.25-9.45 9.48-9.45a9.4 9.4 0 0 1 6.7 2.78 9.38 9.38 0 0 1 2.77 6.68c0 5.21-4.25 9.46-9.47 9.46zm8.06-17.52A11.32 11.32 0 0 0 12.04.66C5.76.66.65 5.76.65 12.04c0 2 .52 3.96 1.52 5.69L.55 23.34l5.73-1.5a11.36 11.36 0 0 0 5.75 1.47h.01c6.28 0 11.39-5.11 11.39-11.39 0-3.04-1.19-5.9-3.33-8.05z"/></svg>WhatsApp</a>
                                    @endif
                                    <a href="{{ route('admin.customers.wallets', ['phone' => $row->phone]) }}" class="text-xs text-gold-700 hover:underline mr-3">Add / take off</a>
                                    <a href="{{ route('admin.customers.wallets.show', $row) }}" class="text-xs text-gold-700 hover:underline">History</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-10 text-center text-ink-700/50">
                                {{ $q ? 'No wallet matches that.' : 'No number has wallet credit yet — add some with the form.' }}
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>{{ $wallets->links() }}</div>

        @if($recent->isNotEmpty())
            <div class="card overflow-hidden">
                <h2 class="font-semibold p-4 border-b border-ink-100">Latest movements</h2>
                @include('admin.customers.partials.wallet-ledger', ['transactions' => $recent, 'showPhone' => true])
            </div>
        @endif
    </div>
</div>
@endsection
