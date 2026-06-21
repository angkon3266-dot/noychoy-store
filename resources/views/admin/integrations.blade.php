@extends('layouts.admin')
@section('title', 'Integrations')
@section('heading', 'Integrations')

@section('content')
<div class="max-w-4xl space-y-6">
    <form action="{{ route('admin.integrations.update') }}" method="POST" class="space-y-6">
        @csrf

        {{-- Steadfast --}}
        <div class="card p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-semibold">Steadfast Courier</h2>
                <span class="badge {{ $steadfastOk ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }}">{{ $steadfastOk ? 'Connected' : 'Not configured' }}</span>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2"><label class="label">Base URL</label><input name="steadfast_base_url" value="{{ $int['steadfast_base_url'] ?? config('steadfast.base_url') }}" class="input"></div>
                <div><label class="label">API Key</label><input name="steadfast_api_key" value="{{ $int['steadfast_api_key'] ?? '' }}" class="input" autocomplete="off"></div>
                <div><label class="label">Secret Key</label><input name="steadfast_secret_key" value="{{ $int['steadfast_secret_key'] ?? '' }}" class="input" autocomplete="off"></div>
                <div><label class="label">Webhook secret (optional)</label><input name="steadfast_webhook_secret" value="{{ $int['steadfast_webhook_secret'] ?? '' }}" class="input" placeholder="A token to secure the webhook"></div>
            </div>
            <div class="mt-4 rounded-lg bg-gold-100/60 p-4 text-sm">
                <p class="font-medium">📡 Live tracking webhook</p>
                <p class="text-ink-700/70 mt-1">Add this URL at <a href="https://steadfast.com.bd/user/webhook/add" target="_blank" class="text-gold-700 underline">steadfast.com.bd/user/webhook/add</a> so delivery status updates automatically on the tracking page:</p>
                <code class="mt-2 block break-all rounded bg-white border border-ink-100 px-3 py-2 text-xs">{{ $webhookUrl }}@if($int['steadfast_webhook_secret'] ?? false)?token={{ $int['steadfast_webhook_secret'] }}@endif</code>
            </div>
        </div>

        {{-- KhudeBarta SMS --}}
        <div class="card p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-semibold">KhudeBarta SMS</h2>
                <span class="badge {{ $smsOk ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }}">{{ $smsOk ? 'Enabled' : 'Disabled' }}</span>
            </div>
            <label class="flex items-center gap-2 text-sm mb-4"><input type="checkbox" name="sms_enabled" value="1" @checked($int['sms_enabled'] ?? false)> Enable SMS sending</label>
            <div class="grid sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2"><label class="label">Base URL</label><input name="sms_base_url" value="{{ $int['sms_base_url'] ?? config('sms.base_url') }}" class="input" placeholder="http://118.67.213.114:3775">
                    <p class="text-xs text-ink-700/50 mt-1">Host &amp; port only — do <strong>not</strong> add <code>/sendtext</code> (it is appended automatically). ⚠️ KhudeBarta whitelists by IP: this server's outbound IP must be authorised in your KhudeBarta panel, otherwise sending returns “Org Client Not Found”.</p>
                </div>
                <div><label class="label">API Key</label><input name="sms_api_key" value="{{ $int['sms_api_key'] ?? '' }}" class="input" autocomplete="off"></div>
                <div><label class="label">Secret Key</label><input name="sms_secret_key" value="{{ $int['sms_secret_key'] ?? '' }}" class="input" autocomplete="off"></div>
                <div><label class="label">Sender ID (callerID) — required</label><input name="sms_caller_id" value="{{ $int['sms_caller_id'] ?? '' }}" class="input" placeholder="Noychoy_Com">
                    <p class="text-xs text-ink-700/50 mt-1">KhudeBarta <strong>requires a sender ID</strong>. Use your masking name (e.g. <code>Noychoy_Com</code>) or, for non-masking, the numeric sender ID KhudeBarta assigned you. It cannot be blank.</p>
                </div>
                @if($smsOk)<div class="flex items-end text-sm text-ink-700/60">Balance: <strong class="ml-1">{{ $smsBalance['statusInfo']['availablebalance'] ?? ($smsBalance['availablebalance'] ?? 'n/a') }}</strong></div>@endif
            </div>
        </div>

        {{-- SMS templates --}}
        <div class="card p-6">
            <h2 class="font-semibold mb-1">SMS templates</h2>
            <p class="text-xs text-ink-700/60 mb-4">Placeholders: <code>{name}</code> <code>{order}</code> <code>{total}</code> <code>{tracking}</code>. Order-placed sends automatically at checkout; delivered/cancelled can fire from the Steadfast webhook or when you change status.</p>
            <div class="space-y-4">
                @foreach($templateLabels as $key => $label)
                    <div>
                        <label class="label">{{ $label }}</label>
                        <textarea name="templates[{{ $key }}]" rows="2" class="input">{{ $templates[$key] }}</textarea>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex justify-end"><button class="btn-primary">Save integrations</button></div>
    </form>

    {{-- Test SMS --}}
    <div class="card p-6">
        <h2 class="font-semibold mb-3">Send a test SMS</h2>
        <form action="{{ route('admin.integrations.test-sms') }}" method="POST" class="flex gap-2 max-w-md">
            @csrf
            <input name="phone" placeholder="01XXXXXXXXX" class="input" required>
            <button class="btn-outline whitespace-nowrap">Send test</button>
        </form>
    </div>

    {{-- Meta product catalog feed --}}
    <div class="card p-6">
        <h2 class="font-semibold mb-1">Meta product catalog feed</h2>
        <p class="text-xs text-ink-700/60 mb-3">In <strong>Meta Commerce Manager → Catalog → Data sources → Add items → Scheduled feed</strong>, paste this URL and set it to refresh daily. Use <code>custom_label_0</code> (your category) to build product sets for category ad campaigns.</p>
        <code class="block break-all rounded bg-white border border-ink-100 px-3 py-2 text-xs">{{ route('feed.meta') }}</code>
        <p class="text-xs text-ink-700/50 mt-2">Per-category feed: append <code>?category=slug</code> (e.g. <code>{{ route('feed.meta') }}?category=rings</code>).</p>
    </div>
</div>
@endsection
