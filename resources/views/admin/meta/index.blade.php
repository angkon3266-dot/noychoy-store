@extends('layouts.admin')
@section('title', 'Meta Integration')
@section('heading', 'Marketing')

@php
    $mode = $settings->mode();
    $configured = $settings->isConfigured();
    $conn = $connectionResult;

    // OAuth wizard step resolution.
    $wizardStep = ! $oauthConfigured ? 3 : ($configured ? 7 : 4);
    $wizardSteps = [
        1 => 'Create Meta App', 2 => 'Configure Redirect URI', 3 => 'Enter App Credentials',
        4 => 'Connect with Facebook', 5 => 'Choose Business', 6 => 'Choose Catalog', 7 => 'Done',
    ];

    $tokenBadge = match ($health['token']) {
        'ok' => ['Valid', 'bg-green-100 text-green-700'],
        'expiring' => ['Expiring soon', 'bg-amber-100 text-amber-700'],
        'expired' => ['Expired', 'bg-red-100 text-red-700'],
        default => ['Missing', 'bg-ink-100 text-ink-700'],
    };
@endphp

@section('content')
<div class="max-w-5xl space-y-6" x-data="metaPage()">
    @include('admin.meta._nav')

    {{-- Reconnect-required banner (site keeps working) --}}
    @if($health['token'] === 'expired')
        <div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm flex items-center justify-between gap-3">
            <span><strong>Reconnect required.</strong> Your access token has expired — product sync is paused, but your store is unaffected. Reconnect to resume the queue.</span>
            @if($mode==='production' && $oauthConfigured)
                <a href="{{ route('admin.meta.oauth.redirect') }}" class="btn-primary shrink-0">Reconnect</a>
            @endif
        </div>
    @endif

    {{-- Notifications --}}
    @foreach($notifications as $note)
        <div class="rounded-md px-4 py-2.5 text-sm border
            {{ $note['type']==='error' ? 'bg-red-50 border-red-200 text-red-800'
             : ($note['type']==='warning' ? 'bg-amber-50 border-amber-200 text-amber-800'
             : 'bg-green-50 border-green-200 text-green-800') }}">{{ $note['message'] }}</div>
    @endforeach

    {{-- Test-connection result --}}
    @if($conn)
        <div class="card p-4 {{ $conn['ok'] ? 'border-green-300' : 'border-red-300' }} border-2">
            <p class="font-semibold {{ $conn['ok'] ? 'text-green-700' : 'text-red-700' }}">{{ $conn['message'] }}</p>
            @if(!empty($conn['checks']))
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach($conn['checks'] as $check)
                        <li class="flex items-center gap-2"><span>{{ $check['ok'] ? '✅' : '❌' }}</span><span>{{ $check['label'] }}</span>@if($check['detail'])<span class="text-ink-700/50">— {{ $check['detail'] }}</span>@endif</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- ── Dashboard stat cards (Part 2) ─────────────────────────────────── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @php
            $cards = [
                ['Products synced', number_format($stats['synced']), 'text-green-600'],
                ['Pending', number_format($stats['pending']), 'text-amber-600'],
                ['Failed', number_format($stats['failed']), 'text-red-600'],
                ['Never synced', number_format($stats['never_synced']), 'text-ink-700/70'],
                ['Success rate', $stats['success_rate'] === null ? '—' : $stats['success_rate'].'%', 'text-gold-700'],
                ["Today's syncs", number_format($stats['today']), 'text-ink-900'],
                ['Last sync', $stats['last_sync'] ? \Illuminate\Support\Carbon::parse($stats['last_sync'])->diffForHumans(null, true) : '—', 'text-ink-900'],
                ['Avg API response', $stats['avg_response_ms'] ? $stats['avg_response_ms'].' ms' : '—', 'text-ink-900'],
            ];
        @endphp
        @foreach($cards as [$label, $value, $color])
            <div class="card p-4">
                <div class="text-xs text-ink-700/50">{{ $label }}</div>
                <div class="text-2xl font-semibold mt-1 {{ $color }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    {{-- ── Connection panel (Part 1) ─────────────────────────────────────── --}}
    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-1.5 text-sm">
                <h3 class="font-semibold mb-1">Connection</h3>
                <p>Status:
                    @if($settings->isEnabled() && $configured)<span class="badge bg-green-100 text-green-700">Connected</span>
                    @elseif($configured)<span class="badge bg-amber-100 text-amber-700">Configured, disabled</span>
                    @else<span class="badge bg-ink-100 text-ink-700">Not connected</span>@endif
                    <span class="ml-1 text-ink-700/50">· {{ ucfirst($mode) }} mode</span>
                </p>
                @if($snapshot['connected_business_name'] || $snapshot['business_id'])<p>Business: <span class="font-medium">{{ $snapshot['connected_business_name'] ?? '—' }}</span> <span class="text-ink-700/40">{{ $snapshot['business_id'] }}</span></p>@endif
                @if($snapshot['connected_catalog_name'] || $snapshot['catalog_id'])<p>Catalog: <span class="font-medium">{{ $snapshot['connected_catalog_name'] ?? '—' }}</span> <span class="text-ink-700/40">{{ $snapshot['catalog_id'] }}</span></p>@endif
                @if($snapshot['pixel_id'])<p>Pixel ID: <span class="font-medium">{{ $snapshot['pixel_id'] }}</span></p>@endif
                @if($snapshot['connected_since'])<p>Connected since: {{ \Illuminate\Support\Carbon::parse($snapshot['connected_since'])->format('d M Y') }}</p>@endif
                @if($snapshot['last_sync_at'])<p>Last sync: {{ \Illuminate\Support\Carbon::parse($snapshot['last_sync_at'])->diffForHumans() }}</p>@endif
            </div>

            {{-- Health indicators --}}
            <div class="space-y-1.5 text-sm">
                <h3 class="font-semibold mb-1">Health</h3>
                <p class="flex items-center gap-2">Token: <span class="badge {{ $tokenBadge[1] }}">{{ $tokenBadge[0] }}</span></p>
                <p class="flex items-center gap-2">Graph API:
                    @if($health['graph_api'] === true)<span class="badge bg-green-100 text-green-700">OK</span>
                    @elseif($health['graph_api'] === false)<span class="badge bg-red-100 text-red-700">Failed</span>
                    @else<span class="badge bg-ink-100 text-ink-700">Untested</span>@endif
                </p>
                <p class="flex items-center gap-2">Webhook:
                    @if($health['webhook_verified'])<span class="badge bg-green-100 text-green-700">Verified</span>
                    @else<span class="badge bg-ink-100 text-ink-700">Not set up</span>@endif
                    <a href="{{ route('admin.meta.webhook') }}" class="text-xs text-gold-700 hover:underline">manage</a>
                </p>
                <p class="flex items-center gap-2">Queue:
                    <span class="badge {{ $settings->get('queue_paused') ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700' }}">{{ $settings->get('queue_paused') ? 'Paused' : 'Active' }}</span>
                    <a href="{{ route('admin.meta.queue') }}" class="text-xs text-gold-700 hover:underline">monitor</a>
                </p>
            </div>

            {{-- Actions --}}
            <div class="flex flex-col gap-2 min-w-44">
                @if($mode==='production' && $oauthConfigured)
                    <a href="{{ route('admin.meta.oauth.redirect') }}" class="btn-outline text-center">{{ $configured ? 'Reconnect' : 'Connect' }}</a>
                @endif
                <form action="{{ route('admin.meta.refresh-catalog') }}" method="POST">@csrf<button class="btn-outline w-full" @disabled(!$configured)>Refresh Catalog</button></form>
                <a href="{{ $commerceManagerUrl }}" target="_blank" rel="noopener" class="btn-outline text-center">Open Commerce Manager ↗</a>
                @if($configured)
                    <form action="{{ route('admin.meta.disconnect') }}" method="POST" onsubmit="return confirm('Disconnect Meta? Credentials cleared, sync history kept.')">@csrf<button class="btn-outline w-full text-red-600">Disconnect</button></form>
                @endif
                <form action="{{ route('admin.meta.lock') }}" method="POST">@csrf<button class="text-xs text-ink-700/50 hover:underline">🔒 Lock module</button></form>
            </div>
        </div>
    </div>

    {{-- Mode switch --}}
    <div class="flex gap-2">
        <form action="{{ route('admin.meta.mode') }}" method="POST">@csrf<input type="hidden" name="mode" value="development"><button class="btn-outline {{ $mode==='development' ? 'ring-2 ring-gold-400' : '' }}">Development Mode</button></form>
        <form action="{{ route('admin.meta.mode') }}" method="POST">@csrf<input type="hidden" name="mode" value="production"><button class="btn-outline {{ $mode==='production' ? 'ring-2 ring-gold-400' : '' }}">Production Mode (OAuth)</button></form>
    </div>

    @if($mode === 'development')
        {{-- ── Development Mode (manual) ────────────────────────────────── --}}
        <div class="rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-4 py-2.5 text-sm">⚠ Development Mode uses a manually-pasted System User token. Best for testing or before your production Meta App is ready.</div>

        <form action="{{ route('admin.meta.save') }}" method="POST" class="card p-5 space-y-4">
            @csrf
            <input type="hidden" name="mode" value="development">
            <label class="flex items-center gap-2 font-medium"><input type="checkbox" name="enabled" value="1" @checked($settings->isEnabled())> Enable Meta Integration</label>
            <div class="grid sm:grid-cols-2 gap-4">
                <div><label class="label">Meta Business ID</label><input name="business_id" value="{{ old('business_id', $snapshot['business_id']) }}" class="input" placeholder="e.g. 1234567890"></div>
                <div><label class="label">Commerce Catalog ID</label><input name="catalog_id" value="{{ old('catalog_id', $snapshot['catalog_id']) }}" class="input" placeholder="e.g. 9876543210"></div>
                <div class="sm:col-span-2">
                    <label class="label">System User Long-Lived Access Token</label>
                    <input type="password" name="access_token" class="input" autocomplete="off" placeholder="{{ $settings->hasToken() ? '•••••••• (saved — leave blank to keep)' : 'Paste your System User token' }}">
                    <p class="text-xs text-ink-700/50 mt-1">Stored encrypted (AES-256). Never a short-lived user token.</p>
                </div>
                <div><label class="label">Pixel ID <span class="text-ink-700/40">(optional)</span></label><input name="pixel_id" value="{{ old('pixel_id', $snapshot['pixel_id']) }}" class="input" placeholder="e.g. 1111111111"></div>
                <div class="sm:col-span-2 rounded-lg border border-ink-100 p-3 space-y-2">
                    <label class="flex items-center gap-2 font-medium"><input type="checkbox" name="capi_enabled" value="1" @checked($snapshot['capi_enabled'] ?? false)> Enable Conversions API (server-side events)</label>
                    <p class="text-xs text-ink-700/50">Sends ViewContent, AddToCart, InitiateCheckout &amp; Purchase server-side, deduplicated with the browser Pixel. Requires a Pixel ID above.</p>
                    <label class="label">CAPI Access Token <span class="text-ink-700/40">(optional)</span></label>
                    <input type="password" name="capi_token" class="input" autocomplete="off" placeholder="{{ ($snapshot['has_capi_token'] ?? false) ? '•••••••• (saved — leave blank to keep)' : 'Leave blank to reuse the System User token above' }}">
                    <p class="text-xs text-ink-700/50">Stored encrypted. Leave blank to reuse the System User token — no separate credential needed unless you use a dedicated Events Manager token.</p>
                </div>
            </div>
            @include('admin.meta._toggles', ['snapshot' => $snapshot])
            <div class="flex flex-wrap gap-2 pt-2 border-t border-ink-100">
                <button class="btn-primary">Save</button>
                <button type="submit" formaction="{{ route('admin.meta.test') }}" class="btn-outline">Test Connection</button>
            </div>
        </form>
    @else
        {{-- ── Production Mode (OAuth wizard) ───────────────────────────── --}}
        <div class="card p-5 space-y-5">
            {{-- Stepper --}}
            <div class="flex items-center gap-1 overflow-x-auto pb-1">
                @foreach($wizardSteps as $n => $label)
                    <div class="flex items-center gap-1 shrink-0">
                        <div class="flex items-center gap-2 px-2">
                            <span class="w-6 h-6 rounded-full grid place-items-center text-xs font-semibold
                                {{ $n < $wizardStep ? 'bg-green-500 text-white' : ($n === $wizardStep ? 'bg-gold-600 text-white' : 'bg-ink-100 text-ink-700/60') }}">
                                {{ $n < $wizardStep ? '✓' : $n }}
                            </span>
                            <span class="text-xs whitespace-nowrap {{ $n === $wizardStep ? 'font-medium' : 'text-ink-700/60' }}">{{ $label }}</span>
                        </div>
                        @if(!$loop->last)<span class="w-5 h-px bg-ink-200"></span>@endif
                    </div>
                @endforeach
            </div>

            @if($configured)
                <div class="rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">✅ Connected to <strong>{{ $snapshot['connected_catalog_name'] ?? 'your catalog' }}</strong>. Automatic sync is enabled. Use <em>Reconnect</em> above to refresh the token.</div>
            @elseif($oauthConfigured)
                <p class="text-sm text-ink-700/70">Connect your Facebook account — no manual token copy/paste. You'll grant catalog access and pick your Business &amp; Commerce Catalog.</p>
                <a href="{{ route('admin.meta.oauth.redirect') }}" class="inline-flex items-center gap-3 rounded-lg bg-[#1877F2] hover:bg-[#1568d8] text-white font-semibold px-6 py-3.5 text-base shadow-sm transition">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12c0-6.627-5.373-12-12-12S0 5.373 0 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874V12h3.328l-.532 3.469h-2.796v8.385C19.612 22.954 24 17.99 24 12z"/></svg>
                    Connect with Facebook
                </a>
            @else
                {{-- Setup guide: OAuth app not configured yet --}}
                <div class="rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm space-y-2">
                    <p class="font-medium">Finish these steps to enable "Connect with Facebook":</p>
                    <ol class="list-decimal list-inside space-y-1">
                        <li><strong>Create a Meta App</strong> at developers.facebook.com → Business type.</li>
                        <li><strong>Add the redirect URI</strong> to Facebook Login settings:<br><code class="break-all bg-white/60 px-1 rounded">{{ route('admin.meta.oauth.callback') }}</code></li>
                        <li><strong>Enter App credentials</strong> — set <code>META_APP_ID</code> and <code>META_APP_SECRET</code> in the server <code>.env</code> (the upcoming System Configuration module will make this editable in-app).</li>
                        <li>Reload this page and click <strong>Connect with Facebook</strong>.</li>
                    </ol>
                    <p>Or use <strong>Development Mode</strong> now with a System User token — no app review needed.</p>
                </div>
            @endif

            {{-- Behaviour toggles still apply in production mode --}}
            <form action="{{ route('admin.meta.save') }}" method="POST" class="space-y-4 pt-3 border-t border-ink-100">
                @csrf
                <input type="hidden" name="mode" value="production">
                <input type="hidden" name="business_id" value="{{ $snapshot['business_id'] }}">
                <input type="hidden" name="catalog_id" value="{{ $snapshot['catalog_id'] }}">
                <input type="hidden" name="pixel_id" value="{{ $snapshot['pixel_id'] }}">
                <label class="flex items-center gap-2 font-medium"><input type="checkbox" name="enabled" value="1" @checked($settings->isEnabled())> Enable Meta Integration</label>
                @include('admin.meta._toggles', ['snapshot' => $snapshot])
                <button class="btn-primary">Save settings</button>
            </form>
        </div>
    @endif

    {{-- ── Sync actions ─────────────────────────────────────────────────── --}}
    <div class="card p-5 space-y-3">
        <div class="flex items-center justify-between">
            <h3 class="font-semibold">Product sync</h3>
            <a href="{{ route('admin.meta.logs') }}" class="text-sm text-gold-700 hover:underline">View sync logs →</a>
        </div>
        <p class="text-sm text-ink-700/60">{{ number_format($eligibleCount) }} product(s) eligible under current settings. All syncs run in the background queue.</p>
        <div class="flex flex-wrap gap-2">
            <form action="{{ route('admin.meta.sync-all') }}" method="POST">@csrf<button class="btn-primary" @disabled(!$configured || !$settings->isEnabled())>Sync All Products</button></form>
            <form action="{{ route('admin.meta.sync-refresh') }}" method="POST" onsubmit="return confirm('Full refresh re-sends every eligible product. Continue?')">@csrf<button class="btn-outline" @disabled(!$configured || !$settings->isEnabled())>Full Catalog Refresh</button></form>
        </div>
        <div x-show="batch.running || batch.finished" x-cloak class="mt-2">
            <div class="flex items-center justify-between text-xs text-ink-700/60 mb-1">
                <span x-text="batch.name + (batch.finished ? ' — done' : ' — running…')"></span>
                <span x-text="batch.processed + ' / ' + batch.total + (batch.failed ? ' (' + batch.failed + ' failed)' : '')"></span>
            </div>
            <div class="h-2 rounded-full bg-ink-100 overflow-hidden"><div class="h-full bg-gold-500 transition-all" :style="'width:' + (batch.progress || 0) + '%'"></div></div>
        </div>
        @if($batch)<script>window.__metaBatch = @json($batch);</script>@endif
    </div>

    {{-- ── Security password ────────────────────────────────────────────── --}}
    <div class="card p-5">
        <h3 class="font-semibold mb-3">Security password</h3>
        <p class="text-sm text-ink-700/60 mb-3">The separate password that unlocks this module. Does not affect your login password.</p>
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
        init() { if (this.batch && this.batch.running) this.poll(); },
        async poll() {
            try {
                const res = await fetch('{{ route('admin.meta.batch-status') }}', { headers: { 'Accept': 'application/json' } });
                this.batch = await res.json();
                if (this.batch.running) setTimeout(() => this.poll(), 2000);
            } catch (e) {}
        }
    };
}
</script>
@endsection
