@extends('layouts.admin')
@section('title', 'Meta Integration')
@section('heading', 'Meta Integration')

@php
    $mode = $settings->mode();
    $configured = $settings->isConfigured();
    $conn = $connectionResult; // structured test-connection result, if just run
@endphp

@section('content')
<div class="max-w-4xl space-y-6" x-data="metaPage()">

    {{-- Notifications --}}
    @foreach($notifications as $note)
        <div class="rounded-md px-4 py-2.5 text-sm border
            {{ $note['type']==='error' ? 'bg-red-50 border-red-200 text-red-800'
             : ($note['type']==='warning' ? 'bg-amber-50 border-amber-200 text-amber-800'
             : 'bg-green-50 border-green-200 text-green-800') }}">
            {{ $note['message'] }}
        </div>
    @endforeach

    {{-- Test-connection result --}}
    @if($conn)
        <div class="card p-4 {{ $conn['ok'] ? 'border-green-300' : 'border-red-300' }} border-2">
            <p class="font-semibold {{ $conn['ok'] ? 'text-green-700' : 'text-red-700' }}">{{ $conn['message'] }}</p>
            @if(!empty($conn['checks']))
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach($conn['checks'] as $check)
                        <li class="flex items-center gap-2">
                            <span>{{ $check['ok'] ? '✅' : '❌' }}</span>
                            <span>{{ $check['label'] }}</span>
                            @if($check['detail'])<span class="text-ink-700/50">— {{ $check['detail'] }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- Connection status summary --}}
    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold mb-1">Connection</h2>
                <div class="text-sm text-ink-700/70 space-y-0.5">
                    <p>Status:
                        @if($settings->isEnabled() && $configured)
                            <span class="badge bg-green-100 text-green-700">Enabled &amp; configured</span>
                        @elseif($configured)
                            <span class="badge bg-amber-100 text-amber-700">Configured, disabled</span>
                        @else
                            <span class="badge bg-ink-100 text-ink-700">Not configured</span>
                        @endif
                    </p>
                    <p>Mode: <span class="font-medium capitalize">{{ $mode }}</span></p>
                    @if($snapshot['connected_business_name'])<p>Business: <span class="font-medium">{{ $snapshot['connected_business_name'] }}</span></p>@endif
                    @if($snapshot['connected_catalog_name'])<p>Catalog: <span class="font-medium">{{ $snapshot['connected_catalog_name'] }}</span></p>@endif
                    @if($snapshot['last_sync_at'])<p>Last sync: {{ \Illuminate\Support\Carbon::parse($snapshot['last_sync_at'])->diffForHumans() }}</p>@endif
                    @if($snapshot['token_expires_at'])
                        <p>Token expires: {{ \Illuminate\Support\Carbon::parse($snapshot['token_expires_at'])->format('d M Y') }}</p>
                    @elseif($settings->hasToken())
                        <p>Token: <span class="text-green-700">stored (never expires / long-lived)</span></p>
                    @endif
                </div>
            </div>
            <div class="flex flex-col gap-2">
                <form action="{{ route('admin.meta.test') }}" method="POST">@csrf<button class="btn-outline w-full" @disabled(!$configured)>Test Connection</button></form>
                @if($configured)
                    <form action="{{ route('admin.meta.disconnect') }}" method="POST" onsubmit="return confirm('Disconnect Meta? Credentials are cleared but sync history is kept.')">@csrf
                        <button class="btn-outline w-full text-red-600">Disconnect</button>
                    </form>
                @endif
                <form action="{{ route('admin.meta.lock') }}" method="POST">@csrf<button class="text-xs text-ink-700/50 hover:underline">🔒 Lock module</button></form>
            </div>
        </div>
    </div>

    {{-- Mode switch --}}
    <div class="flex gap-2">
        <form action="{{ route('admin.meta.mode') }}" method="POST">@csrf<input type="hidden" name="mode" value="development">
            <button class="btn-outline {{ $mode==='development' ? 'ring-2 ring-gold-400' : '' }}">Development Mode</button>
        </form>
        <form action="{{ route('admin.meta.mode') }}" method="POST">@csrf<input type="hidden" name="mode" value="production">
            <button class="btn-outline {{ $mode==='production' ? 'ring-2 ring-gold-400' : '' }}">Production Mode (OAuth)</button>
        </form>
    </div>

    {{-- ── Development Mode (manual) ────────────────────────────────────── --}}
    @if($mode === 'development')
        <div class="rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-4 py-2.5 text-sm">
            ⚠ Development Mode uses a manually-pasted System User token. Best for testing or before your production Meta App is ready.
        </div>

        <form action="{{ route('admin.meta.save') }}" method="POST" class="card p-5 space-y-4">
            @csrf
            <input type="hidden" name="mode" value="development">

            <label class="flex items-center gap-2 font-medium">
                <input type="checkbox" name="enabled" value="1" @checked($settings->isEnabled())>
                Enable Meta Integration
            </label>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Meta Business ID</label>
                    <input name="business_id" value="{{ old('business_id', $snapshot['business_id']) }}" class="input" placeholder="e.g. 1234567890">
                </div>
                <div>
                    <label class="label">Commerce Catalog ID</label>
                    <input name="catalog_id" value="{{ old('catalog_id', $snapshot['catalog_id']) }}" class="input" placeholder="e.g. 9876543210">
                </div>
                <div class="sm:col-span-2">
                    <label class="label">System User Long-Lived Access Token</label>
                    <input type="password" name="access_token" class="input" autocomplete="off"
                           placeholder="{{ $settings->hasToken() ? '•••••••• (saved — leave blank to keep)' : 'Paste your System User token' }}">
                    <p class="text-xs text-ink-700/50 mt-1">Stored encrypted (AES-256). Never a short-lived user token — generate a System User token in Business Settings.</p>
                </div>
                <div>
                    <label class="label">Pixel ID <span class="text-ink-700/40">(optional)</span></label>
                    <input name="pixel_id" value="{{ old('pixel_id', $snapshot['pixel_id']) }}" class="input" placeholder="e.g. 1111111111">
                </div>
            </div>

            @include('admin.meta._toggles', ['snapshot' => $snapshot])

            <div class="flex flex-wrap gap-2 pt-2 border-t border-ink-100">
                <button class="btn-primary">Save</button>
                <button type="submit" formaction="{{ route('admin.meta.test') }}" class="btn-outline">Test Connection</button>
            </div>
        </form>
    @else
        {{-- ── Production Mode (OAuth) ──────────────────────────────────── --}}
        <div class="card p-5 space-y-4">
            @if($oauthConfigured)
                <p class="text-sm text-ink-700/70">Connect your Facebook account — no manual token copy/paste. You'll pick your Business and Commerce Catalog, and automatic sync turns on.</p>
                <a href="{{ route('admin.meta.oauth.redirect') }}" class="btn-primary inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12c0-6.627-5.373-12-12-12S0 5.373 0 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874V12h3.328l-.532 3.469h-2.796v8.385C19.612 22.954 24 17.99 24 12z"/></svg>
                    {{ $configured ? 'Reconnect with Facebook' : 'Connect with Facebook' }}
                </a>
                @if($configured)
                    <p class="text-xs text-ink-700/50">Already connected to {{ $snapshot['connected_catalog_name'] ?? 'a catalog' }}. Reconnecting refreshes the token.</p>
                @endif
            @else
                <div class="rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-4 py-2.5 text-sm">
                    Production OAuth is not configured on this server. Set <code>META_APP_ID</code> and <code>META_APP_SECRET</code> in <code>.env</code> (your Meta App), whitelist the redirect URI
                    <code class="break-all">{{ route('admin.meta.oauth.callback') }}</code>, then use this button. Until then, use Development Mode.
                </div>
            @endif

            {{-- Behaviour toggles still apply in production mode --}}
            <form action="{{ route('admin.meta.save') }}" method="POST" class="space-y-4 pt-2 border-t border-ink-100">
                @csrf
                <input type="hidden" name="mode" value="production">
                <input type="hidden" name="business_id" value="{{ $snapshot['business_id'] }}">
                <input type="hidden" name="catalog_id" value="{{ $snapshot['catalog_id'] }}">
                <input type="hidden" name="pixel_id" value="{{ $snapshot['pixel_id'] }}">
                <label class="flex items-center gap-2 font-medium">
                    <input type="checkbox" name="enabled" value="1" @checked($settings->isEnabled())>
                    Enable Meta Integration
                </label>
                @include('admin.meta._toggles', ['snapshot' => $snapshot])
                <button class="btn-primary">Save settings</button>
            </form>
        </div>
    @endif

    {{-- ── Sync actions ─────────────────────────────────────────────────── --}}
    <div class="card p-5 space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold">Product sync</h2>
            <a href="{{ route('admin.meta.logs') }}" class="text-sm text-gold-700 hover:underline">View sync logs →</a>
        </div>
        <p class="text-sm text-ink-700/60">{{ number_format($eligibleCount) }} product(s) are eligible for the catalog under your current settings. All syncs run in the background queue.</p>

        <div class="flex flex-wrap gap-2">
            <form action="{{ route('admin.meta.sync-all') }}" method="POST">@csrf<button class="btn-primary" @disabled(!$configured || !$settings->isEnabled())>Sync All Products</button></form>
            <form action="{{ route('admin.meta.sync-refresh') }}" method="POST" onsubmit="return confirm('Full refresh re-sends every eligible product to Meta. Continue?')">@csrf<button class="btn-outline" @disabled(!$configured || !$settings->isEnabled())>Full Catalog Refresh</button></form>
        </div>

        {{-- Batch progress --}}
        <div x-show="batch.running || batch.finished" x-cloak class="mt-2">
            <div class="flex items-center justify-between text-xs text-ink-700/60 mb-1">
                <span x-text="batch.name + (batch.finished ? ' — done' : ' — running…')"></span>
                <span x-text="batch.processed + ' / ' + batch.total + (batch.failed ? ' (' + batch.failed + ' failed)' : '')"></span>
            </div>
            <div class="h-2 rounded-full bg-ink-100 overflow-hidden">
                <div class="h-full bg-gold-500 transition-all" :style="'width:' + (batch.progress || 0) + '%'"></div>
            </div>
        </div>
        @if($batch)<script>window.__metaBatch = @json($batch);</script>@endif
    </div>

    {{-- ── Security password ────────────────────────────────────────────── --}}
    <div class="card p-5">
        <h2 class="font-semibold mb-3">Security password</h2>
        <p class="text-sm text-ink-700/60 mb-3">The separate password that unlocks this module. Changing it does not affect your login password.</p>
        <form action="{{ route('admin.meta.password.update') }}" method="POST" class="grid sm:grid-cols-3 gap-3">
            @csrf
            <div><label class="label">Current</label><input type="password" name="current_password" class="input" autocomplete="off"></div>
            <div><label class="label">New</label><input type="password" name="new_password" class="input" autocomplete="new-password"></div>
            <div><label class="label">Confirm new</label><input type="password" name="new_password_confirmation" class="input" autocomplete="new-password"></div>
            <div class="sm:col-span-3"><button class="btn-outline">Update security password</button></div>
        </form>
    </div>
</div>

<script>
function metaPage() {
    return {
        batch: window.__metaBatch || { running: false, finished: false, total: 0, processed: 0, failed: 0, progress: 0, name: '' },
        init() {
            if (this.batch && this.batch.running) this.poll();
        },
        async poll() {
            try {
                const res = await fetch('{{ route('admin.meta.batch-status') }}', { headers: { 'Accept': 'application/json' } });
                this.batch = await res.json();
                if (this.batch.running) setTimeout(() => this.poll(), 2000);
            } catch (e) { /* ignore transient errors */ }
        }
    };
}
</script>
@endsection
