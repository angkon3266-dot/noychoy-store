@extends('layouts.admin')
@section('title', 'Meta Webhook')
@section('heading', 'Marketing')

@section('content')
<div class="max-w-3xl space-y-6" x-data="{ copied: false, copy(t){ navigator.clipboard.writeText(t); this.copied = true; setTimeout(()=>this.copied=false, 1500); } }">
    @include('admin.meta._nav')

    <div class="card p-5 space-y-4">
        <h3 class="font-semibold">Webhook endpoint</h3>

        <div>
            <label class="label">Callback URL</label>
            <div class="flex gap-2">
                <input class="input font-mono text-xs" readonly value="{{ $webhookUrl }}" onclick="this.select()">
                <button type="button" class="btn-outline shrink-0" @click="copy('{{ $webhookUrl }}')"><span x-text="copied ? 'Copied!' : 'Copy'"></span></button>
            </div>
            <p class="text-xs text-ink-700/50 mt-1">Paste this into your Meta App → Webhooks → Callback URL.</p>
        </div>

        <div class="grid sm:grid-cols-2 gap-4 text-sm">
            <div>
                <div class="text-ink-700/50 text-xs">Verify token</div>
                @if($verifyTokenSet)
                    <span class="badge bg-green-100 text-green-700">Configured</span>
                @else
                    <span class="badge bg-amber-100 text-amber-700">Not set</span>
                    <p class="text-xs text-ink-700/50 mt-1">Set <code>META_WEBHOOK_VERIFY_TOKEN</code> in <code>.env</code>, then use the same value in Meta.</p>
                @endif
            </div>
            <div>
                <div class="text-ink-700/50 text-xs">Verification status</div>
                @if($snapshot['webhook_verified_at'])
                    <span class="badge bg-green-100 text-green-700">Verified</span>
                    <p class="text-xs text-ink-700/50 mt-1">{{ \Illuminate\Support\Carbon::parse($snapshot['webhook_verified_at'])->diffForHumans() }}</p>
                @else
                    <span class="badge bg-ink-100 text-ink-700">Awaiting handshake</span>
                @endif
            </div>
        </div>

        <div>
            <div class="text-ink-700/50 text-xs">Last event received</div>
            @if($snapshot['last_webhook_event'])
                <p class="text-sm">{{ \Illuminate\Support\Carbon::parse($snapshot['last_webhook_event']['at'])->diffForHumans() }}</p>
                <pre class="text-xs bg-ink-50 rounded p-2 mt-1 overflow-x-auto text-ink-700/70">{{ $snapshot['last_webhook_event']['summary'] }}</pre>
            @else
                <p class="text-sm text-ink-700/50">No events received yet.</p>
            @endif
        </div>
    </div>

    <div class="card p-5">
        <h3 class="font-semibold mb-2">How to connect</h3>
        <ol class="list-decimal list-inside text-sm text-ink-700/70 space-y-1">
            <li>Open your Meta App → <strong>Webhooks</strong>.</li>
            <li>Set the <strong>Callback URL</strong> (above) and <strong>Verify token</strong> (your <code>META_WEBHOOK_VERIFY_TOKEN</code>).</li>
            <li>Meta calls the URL once to verify — the badge above turns green on success.</li>
            <li>Subscribe to the fields you want; deliveries are signed and validated (<code>X-Hub-Signature-256</code>).</li>
        </ol>
    </div>
</div>
@endsection
