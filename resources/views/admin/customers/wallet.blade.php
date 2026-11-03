@extends('layouts.admin')
@section('title', 'Wallet · '.$wallet->phone)
@section('heading', 'Wallet · '.$wallet->phone)

@section('content')
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('admin.customers.wallets') }}" class="btn-outline py-2 text-sm">← All wallets</a>
    @if($customer)
        <a href="{{ route('admin.customers.show', $customer->id) }}" class="btn-outline py-2 text-sm">{{ $customer->name }}</a>
    @endif
    <a href="{{ route('admin.customers.wallets', ['phone' => $wallet->phone]) }}" class="btn-primary py-2 text-sm">Add / take off money</a>
    @if($whatsapp)
        <a href="{{ $whatsapp }}" target="_blank" rel="noopener"
           class="inline-flex items-center gap-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15-.2.3-.77.96-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.79-1.47-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.18.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.6-.92-2.2-.24-.58-.49-.5-.67-.5h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.75-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.04 21.5h-.01a9.45 9.45 0 0 1-4.82-1.32l-.35-.2-3.58.94.96-3.49-.23-.36a9.43 9.43 0 0 1-1.45-5.04c0-5.21 4.25-9.45 9.48-9.45a9.4 9.4 0 0 1 6.7 2.78 9.38 9.38 0 0 1 2.77 6.68c0 5.21-4.25 9.46-9.47 9.46zm8.06-17.52A11.32 11.32 0 0 0 12.04.66C5.76.66.65 5.76.65 12.04c0 2 .52 3.96 1.52 5.69L.55 23.34l5.73-1.5a11.36 11.36 0 0 0 5.75 1.47h.01c6.28 0 11.39-5.11 11.39-11.39 0-3.04-1.19-5.9-3.33-8.05z"/></svg>
            Message on WhatsApp
        </a>
    @endif
</div>

<div class="card p-5 mb-6 max-w-xs">
    <div class="text-xs text-ink-700/50">Balance</div>
    <div class="text-3xl font-semibold {{ (float) $wallet->balance > 0 ? 'text-green-700' : '' }}">{{ money($wallet->balance) }}</div>
</div>

<div class="card overflow-hidden">
    @include('admin.customers.partials.wallet-ledger', ['transactions' => $transactions])
</div>

<div class="mt-6">{{ $transactions->links() }}</div>
@endsection
