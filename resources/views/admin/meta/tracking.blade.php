@extends('layouts.admin')
@section('title', 'Meta Tracking')
@section('heading', 'Marketing')

@php
    $pixelId = $snapshot['pixel_id'] ?? null;
    $badge = fn ($on) => $on ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700';
    $dedupActive = $pixelEnabled && $capiEnabled;
@endphp

@section('content')
<div class="max-w-5xl space-y-6"
     x-data="metaTracking({
        testBase: @js(url('admin/meta/tracking/test')),
        diagnosticsUrl: @js(route('admin.meta.tracking.diagnostics')),
        validateUrl: @js(route('admin.meta.tracking.validate-token')),
        pixelId: @js($pixelId),
        pixelEnabled: @js($pixelEnabled),
        recent: @js($recent),
     })">
    @include('admin.meta._nav')

    {{-- ── Status cards ─────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="card p-4">
            <div class="text-xs text-ink-700/50">Browser Pixel</div>
            <div class="mt-1"><span class="badge {{ $badge($pixelEnabled) }}">{{ $pixelEnabled ? 'Active' : 'Off' }}</span></div>
            <div class="text-xs text-ink-700/40 mt-1">{{ $pixelId ?: 'No Pixel ID' }}</div>
        </div>
        <div class="card p-4">
            <div class="text-xs text-ink-700/50">Conversions API</div>
            <div class="mt-1"><span class="badge {{ $badge($capiEnabled) }}">{{ $capiEnabled ? 'Active' : 'Off' }}</span></div>
            <div class="text-xs text-ink-700/40 mt-1">{{ ($snapshot['has_capi_token'] ?? false) ? 'Dedicated token' : 'System-user token' }}</div>
        </div>
        <div class="card p-4">
            <div class="text-xs text-ink-700/50">Deduplication</div>
            <div class="mt-1"><span class="badge {{ $badge($dedupActive) }}">{{ $dedupActive ? 'Active' : 'Inactive' }}</span></div>
            <div class="text-xs text-ink-700/40 mt-1">Shared <code>event_id</code></div>
        </div>
        <div class="card p-4">
            <div class="text-xs text-ink-700/50">Last event sent</div>
            <div class="text-lg font-semibold mt-1">{{ $lastEventSent ? \Illuminate\Support\Carbon::parse($lastEventSent)->diffForHumans(null, true) : '—' }}</div>
        </div>
    </div>

    {{-- ── Settings ─────────────────────────────────────────────────────────── --}}
    <form method="POST" action="{{ route('admin.meta.tracking.save') }}" class="card p-5 space-y-5">
        @csrf

        <div>
            <h3 class="font-semibold mb-1">Browser Pixel</h3>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="pixel_enabled" value="1" @checked($snapshot['pixel_enabled'] ?? true)> Enable browser Pixel</label>
            <div class="grid sm:grid-cols-2 gap-3 mt-3">
                <div><label class="label">Pixel ID</label><input name="pixel_id" value="{{ old('pixel_id', $pixelId) }}" class="input" placeholder="e.g. 1111111111"></div>
                <label class="flex items-center gap-2 text-sm mt-6"><input type="checkbox" name="advanced_matching" value="1" @checked($advancedMatching)> Advanced Matching</label>
            </div>
            <p class="label mt-3">Events</p>
            <div class="grid sm:grid-cols-3 gap-2 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" name="track_pageview" value="1" @checked($snapshot['track_pageview'] ?? true)> PageView</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="track_viewcontent" value="1" @checked($snapshot['track_viewcontent'] ?? true)> ViewContent</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="track_search" value="1" @checked($snapshot['track_search'] ?? true)> Search</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="track_addtocart" value="1" @checked($snapshot['track_addtocart'] ?? true)> AddToCart</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="track_initiatecheckout" value="1" @checked($snapshot['track_initiatecheckout'] ?? true)> InitiateCheckout</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="track_purchase" value="1" @checked($snapshot['track_purchase'] ?? true)> Purchase</label>
            </div>
        </div>

        <div class="border-t border-ink-100 pt-4">
            <h3 class="font-semibold mb-1">Conversions API</h3>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="capi_enabled" value="1" @checked($capiEnabled)> Enable Conversions API (server-side)</label>
            <div class="grid sm:grid-cols-2 gap-3 mt-3">
                <div>
                    <label class="label">CAPI Access Token</label>
                    <input type="password" name="capi_token" class="input" autocomplete="off"
                           placeholder="{{ ($snapshot['has_capi_token'] ?? false) ? '•••••••• (saved — leave blank to keep)' : 'Leave blank to reuse the System User token' }}">
                    <p class="text-xs text-ink-700/50 mt-1">Stored encrypted (AES-256). Blank reuses the System User token.</p>
                </div>
                <div><label class="label">Test Event Code <span class="text-ink-700/40">(optional)</span></label><input name="test_event_code" value="{{ old('test_event_code', $testEventCode) }}" class="input" placeholder="TEST12345"></div>
            </div>
            <div class="mt-3 flex items-center gap-3">
                <button type="button" @click="validate()" class="btn-outline text-sm" :disabled="tokenLoading">
                    <span x-show="!tokenLoading">Validate token</span><span x-show="tokenLoading">Validating…</span>
                </button>
                <template x-if="tokenInfo">
                    <span class="text-sm" :class="tokenInfo.valid ? 'text-green-700' : 'text-red-700'">
                        <span x-show="tokenInfo.valid">✅ Valid<span x-show="tokenInfo.expires_at"> · expires <span x-text="new Date(tokenInfo.expires_at*1000).toISOString().slice(0,10)"></span></span></span>
                        <span x-show="!tokenInfo.valid">❌ <span x-text="tokenInfo.error || 'Invalid'"></span></span>
                    </span>
                </template>
            </div>
        </div>

        <div class="border-t border-ink-100 pt-3">
            <button class="btn-primary">Save tracking settings</button>
        </div>
    </form>

    {{-- ── Deduplication strategy ───────────────────────────────────────────── --}}
    <div class="card p-5 text-sm space-y-1.5">
        <h3 class="font-semibold mb-1">Deduplication</h3>
        <p>Browser Pixel: <span class="badge {{ $badge($pixelEnabled) }}">{{ $pixelEnabled ? 'On' : 'Off' }}</span></p>
        <p>Conversions API: <span class="badge {{ $badge($capiEnabled) }}">{{ $capiEnabled ? 'On' : 'Off' }}</span></p>
        <p>Deduplication: <span class="badge {{ $badge($dedupActive) }}">{{ $dedupActive ? 'Active' : 'Inactive' }}</span></p>
        <p class="text-ink-700/60 mt-2">Event ID strategy: a single <code>event_id</code> is generated server-side (or in JS for AddToCart) and sent by <strong>both</strong> the browser Pixel (<code>fbq(..., {eventID})</code>) and the Conversions API. <code>content_ids</code> use the catalog <code>retailer_id</code> (<code>prod-&lt;id&gt;</code>). Meta collapses the two into one event.</p>
    </div>

    {{-- ── Test events ──────────────────────────────────────────────────────── --}}
    <div class="card p-5">
        <h3 class="font-semibold mb-1">Test events</h3>
        <p class="text-xs text-ink-700/50 mb-3">Fires a real Conversions API event (with your Test Event Code) and, when the Pixel is configured, the matching browser event with the same <code>event_id</code>. Check Events Manager → Test Events.</p>
        <div class="flex flex-wrap gap-2">
            @foreach(['PageView','ViewContent','Search','AddToCart','InitiateCheckout','Purchase'] as $ev)
                <button type="button" @click="test('{{ $ev }}')" class="btn-outline text-sm" :disabled="busy==='{{ $ev }}'">
                    <span x-show="busy!=='{{ $ev }}'">Send {{ $ev }}</span><span x-show="busy==='{{ $ev }}'">Sending…</span>
                </button>
            @endforeach
        </div>

        <template x-if="result">
            <div class="mt-4 rounded-lg border p-3 text-sm" :class="result.ok ? 'border-green-300 bg-green-50' : 'border-red-300 bg-red-50'">
                <div class="grid sm:grid-cols-2 gap-x-6 gap-y-1">
                    <div>Event: <span class="font-medium" x-text="result.event"></span></div>
                    <div>HTTP Status: <span class="font-medium" x-text="result.status"></span></div>
                    <div class="sm:col-span-2 break-all">Event ID: <code x-text="result.event_id"></code></div>
                    <div>Deduplicated: <span x-text="result.deduplicated ? '✅ yes (browser + CAPI)' : (result.browser_sent ? '—' : 'CAPI only')"></span></div>
                    <div>Response time: <span x-text="result.ms + ' ms'"></span></div>
                    <div x-show="result.test_event_code">Test code: <span x-text="result.test_event_code"></span></div>
                    <div x-show="result.error" class="sm:col-span-2 text-red-700">Error: <span x-text="result.error"></span></div>
                </div>
                <details class="mt-2"><summary class="cursor-pointer text-ink-700/60">Meta response</summary>
                    <pre class="mt-1 text-xs bg-white/70 rounded p-2 overflow-x-auto" x-text="JSON.stringify(result.body, null, 2)"></pre>
                </details>
            </div>
        </template>
    </div>

    {{-- ── Event debugger (recent test events) ──────────────────────────────── --}}
    <div class="card p-5">
        <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
            <h3 class="font-semibold">Event debugger</h3>
            <div class="flex gap-2 text-sm">
                <select x-model="filterEvent" class="input py-1 text-sm">
                    <option value="">All events</option>
                    @foreach(['PageView','ViewContent','Search','AddToCart','InitiateCheckout','Purchase'] as $ev)<option>{{ $ev }}</option>@endforeach
                </select>
                <select x-model="filterStatus" class="input py-1 text-sm">
                    <option value="">Any status</option>
                    <option value="ok">Success</option>
                    <option value="fail">Failed</option>
                </select>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-ink-700/50 border-b border-ink-100">
                    <tr><th class="py-1.5 pr-3">Event</th><th class="pr-3">Event ID</th><th class="pr-3">SKU</th><th class="pr-3">Pixel</th><th class="pr-3">CAPI</th><th class="pr-3">Dedup</th><th class="pr-3">HTTP</th><th class="pr-3">When</th></tr>
                </thead>
                <tbody>
                    <template x-for="(e, i) in filteredRecent" :key="i">
                        <tr class="border-b border-ink-50">
                            <td class="py-1.5 pr-3 font-medium" x-text="e.event"></td>
                            <td class="pr-3"><code class="text-[11px]" x-text="(e.event_id||'').slice(0,18)+'…'"></code></td>
                            <td class="pr-3" x-text="e.sku"></td>
                            <td class="pr-3" x-text="e.browser_sent ? '✅' : '—'"></td>
                            <td class="pr-3" x-text="e.ok ? '✅' : '❌'"></td>
                            <td class="pr-3" x-text="e.deduplicated ? '✅' : '—'"></td>
                            <td class="pr-3" x-text="e.status"></td>
                            <td class="pr-3 text-ink-700/50" x-text="new Date(e.at).toLocaleString()"></td>
                        </tr>
                    </template>
                    <template x-if="!filteredRecent.length"><tr><td colspan="8" class="py-3 text-ink-700/40">No events recorded yet — send a test event above.</td></tr></template>
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Diagnostics + health score ───────────────────────────────────────── --}}
    <div class="card p-5">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h3 class="font-semibold">Meta diagnostics</h3>
            <button type="button" @click="runDiagnostics()" class="btn-primary text-sm" :disabled="diagLoading">
                <span x-show="!diagLoading">Run diagnostics</span><span x-show="diagLoading">Running…</span>
            </button>
        </div>

        <template x-if="diag">
            <div>
                <div class="flex items-center gap-3 mb-3">
                    <div class="text-3xl font-bold" :class="diag.score>=80 ? 'text-green-600' : (diag.score>=50 ? 'text-amber-600' : 'text-red-600')" x-text="diag.score + '%'"></div>
                    <div class="flex-1 h-2 rounded-full bg-ink-100 overflow-hidden">
                        <div class="h-full rounded-full" :class="diag.score>=80 ? 'bg-green-500' : (diag.score>=50 ? 'bg-amber-500' : 'bg-red-500')" :style="`width:${diag.score}%`"></div>
                    </div>
                    <div class="text-xs text-ink-700/50">API <span x-text="diag.api_version"></span></div>
                </div>
                <ul class="space-y-1.5 text-sm">
                    <template x-for="c in diag.checks" :key="c.key">
                        <li class="flex items-start gap-2">
                            <span x-text="c.ok===true ? '🟢' : (c.ok===false ? '🔴' : '⚪')"></span>
                            <div>
                                <span class="font-medium" x-text="c.label"></span>
                                <span class="text-ink-700/50" x-show="c.detail"> — <span x-text="c.detail"></span></span>
                                <div class="text-xs text-amber-700" x-show="c.ok===false && c.fix" x-text="'Fix: ' + c.fix"></div>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>
        </template>
        <p x-show="!diag" class="text-sm text-ink-700/50">Click <strong>Run diagnostics</strong> to test Graph API, Catalog, Feed, Pixel, CAPI, Queue, Token and Webhook live.</p>
    </div>
</div>

{{-- Contained Pixel loader so Test buttons can fire real browser events with the
     shared event_id (only when a Pixel ID is configured). --}}
@if($pixelId)
<script>
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
    document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', @json($pixelId));
</script>
@endif

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('metaTracking', (cfg) => ({
            ...cfg,
            csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
            busy: '',
            result: null,
            recent: cfg.recent || [],
            filterEvent: '',
            filterStatus: '',
            tokenInfo: null,
            tokenLoading: false,
            diag: null,
            diagLoading: false,

            get filteredRecent() {
                return this.recent.filter((e) => {
                    if (this.filterEvent && e.event !== this.filterEvent) return false;
                    if (this.filterStatus === 'ok' && !e.ok) return false;
                    if (this.filterStatus === 'fail' && e.ok) return false;
                    return true;
                });
            },

            sampleParams(event) {
                const base = { content_type: 'product', content_ids: ['prod-test'], currency: 'BDT', value: 1 };
                if (event === 'InitiateCheckout' || event === 'Purchase') return { ...base, num_items: 1 };
                if (event === 'ViewContent' || event === 'AddToCart') return base;
                return {};
            },

            async test(event) {
                this.busy = event;
                const eventId = event + '.' + ((self.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now() + '-' + Math.random().toString(16).slice(2)));
                let browserSent = false;
                if (this.pixelEnabled && window.fbq) {
                    try { fbq('track', event, this.sampleParams(event), { eventID: eventId }); browserSent = true; } catch (e) {}
                }
                try {
                    const res = await fetch(this.testBase + '/' + event, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                        body: JSON.stringify({ event_id: eventId, browser_sent: browserSent }),
                    });
                    const d = await res.json();
                    this.result = d;
                    this.recent.unshift({ event: d.event, event_id: d.event_id, sku: d.sku || 'prod-test', ok: d.ok, status: d.status, ms: d.ms, error: d.error, browser_sent: d.browser_sent, deduplicated: d.deduplicated, at: new Date().toISOString() });
                } catch (e) {
                    this.result = { ok: false, event, status: 0, ms: 0, error: String(e), event_id: eventId, body: null };
                }
                this.busy = '';
            },

            async validate() {
                this.tokenLoading = true;
                try { const r = await fetch(this.validateUrl, { headers: { Accept: 'application/json' } }); this.tokenInfo = await r.json(); }
                catch (e) { this.tokenInfo = { valid: false, error: String(e) }; }
                this.tokenLoading = false;
            },

            async runDiagnostics() {
                this.diagLoading = true;
                try { const r = await fetch(this.diagnosticsUrl, { headers: { Accept: 'application/json' } }); this.diag = await r.json(); }
                catch (e) { this.diag = { checks: [], score: 0, api_version: '' }; }
                this.diagLoading = false;
            },
        }));
    });
</script>
@endsection
