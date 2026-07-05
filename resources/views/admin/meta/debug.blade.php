@extends('layouts.admin')
@section('title', 'Meta Debug')
@section('heading', 'Meta Integration — Debug Mode')

@section('content')
<div class="max-w-6xl space-y-6" x-data="metaDebug()">

    <div class="rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm">
        <strong>Debug Mode is ON.</strong> Every Graph call is logged to
        <code>storage/logs/meta-debug.log</code> with a unique Request ID. This is a temporary diagnostic tool.
    </div>

    {{-- ── Context / connection status ─────────────────────────────────────── --}}
    <div class="card p-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold">Connection context</h2>
            <button type="button" @click="copy(@js($context))" class="btn-outline text-xs py-1">Copy JSON</button>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-2 text-sm">
            @php $rows = [
                'OAuth status' => ($context['connected'] ?? false) ? 'Connected' : 'Not connected',
                'Token source' => $context['token_source'] ?? '—',
                'Connected user' => $context['user_email'] ?? '—',
                'Business portfolio' => $context['business_name'] ?? '—',
                'Business ID' => $context['business_portfolio_id'] ?? '—',
                'Selected catalog ID' => $context['selected_catalog_id'] ?? '—',
                'Selected catalog name' => $context['selected_catalog_name'] ?? '—',
                'App ID' => $context['app_id'] ?? '—',
                'Login for Business Config ID' => $context['login_config_id'] ?? '—',
                'Graph API version' => $context['graph_version'] ?? '—',
                'Token expiration' => $context['token_expires_at'] ?? 'never / unknown',
                'Request ID' => $context['request_id'] ?? '—',
            ]; @endphp
            @foreach($rows as $label => $val)
                <div>
                    <div class="text-xs text-ink-700/50">{{ $label }}</div>
                    <div class="font-mono text-[13px] break-all">{{ $val }}</div>
                </div>
            @endforeach
        </div>
        <div class="mt-3">
            <div class="text-xs text-ink-700/50 mb-1">Granted permissions</div>
            @forelse($context['granted_scopes'] ?? [] as $s)
                <span class="badge bg-green-100 text-green-700 mr-1 mb-1">{{ $s }}</span>
            @empty
                <span class="text-sm text-ink-700/50">None recorded.</span>
            @endforelse
        </div>
    </div>

    {{-- ── Readiness flags (why buttons are / aren't gated) ────────────────── --}}
    <div class="card p-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold">Readiness</h2>
            <button type="button" @click="copy(@js($readiness))" class="btn-outline text-xs py-1">Copy JSON</button>
        </div>
        <p class="text-xs text-ink-700/60 mb-3">The testers only require a token. They are <strong>not</strong> gated by business/catalog selection — buttons are disabled only while a request is in flight.</p>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-1.5 text-sm">
            @foreach($readiness as $k => $v)
                <div class="flex items-center justify-between gap-2 border-b border-ink-50 py-1">
                    <span class="text-ink-700/60 font-mono text-xs">{{ $k }}</span>
                    <span class="font-mono text-xs break-all text-right">
                        @if(is_bool($v))
                            <span class="badge {{ $v ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }}">{{ $v ? 'true' : 'false' }}</span>
                        @elseif(is_array($v))
                            {{ empty($v) ? '[]' : implode(', ', $v) }}
                        @else
                            {{ $v === null || $v === '' ? '—' : $v }}
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ── Discovery tester ────────────────────────────────────────────────── --}}
    <div class="card p-5">
        <h2 class="font-semibold mb-1">Discovery tester</h2>
        <p class="text-xs text-ink-700/60 mb-3">Each test runs independently and logs its HTTP request, raw JSON, parsed result, execution time and errors.</p>
        <div class="flex flex-wrap gap-2">
            @php $tests = ['oauth'=>'Test OAuth','businesses'=>'Test Businesses','catalogs'=>'Test Catalogs','pages'=>'Test Pages','instagram'=>'Test Instagram','pixels'=>'Test Pixels','ad_accounts'=>'Test Ad Accounts']; @endphp
            @foreach($tests as $key => $label)
                <button type="button" @click="run('{{ $key }}')" :disabled="running"
                        class="btn-outline text-sm py-1.5" :class="running==='{{ $key }}' && 'opacity-60'">
                    <span x-show="running==='{{ $key }}'">…</span>{{ $label }}
                </button>
            @endforeach
            <button type="button" @click="run('discovery')" :disabled="running" class="btn-primary text-sm py-1.5">Re-run full discovery</button>
        </div>

        <div class="flex flex-wrap items-end gap-2 mt-3 pt-3 border-t border-ink-100">
            <div class="flex-1 min-w-[12rem]">
                <label class="label text-xs">Test Graph API — GET path</label>
                <input x-model="graphPath" placeholder="e.g. me, {business-id}/owned_product_catalogs" class="input py-1.5 text-sm font-mono">
            </div>
            <button type="button" @click="run('graph', { path: graphPath })" :disabled="running" class="btn-outline text-sm py-1.5">Run Graph GET</button>
        </div>
    </div>

    {{-- ── Result panel ────────────────────────────────────────────────────── --}}
    <div class="card p-5" x-show="result" x-cloak>
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold">Result <span class="text-xs font-normal text-ink-700/50" x-text="result && ('· '+result.what)"></span></h2>
            <div class="flex items-center gap-2">
                <span class="badge" :class="result && result.ok ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'" x-text="result && (result.ok ? 'OK' : 'Issues found')"></span>
                <button type="button" @click="copy(result)" class="btn-outline text-xs py-1">Copy JSON</button>
            </div>
        </div>

        {{-- Notes / explanations --}}
        <template x-if="result && result.notes && result.notes.length">
            <ul class="mb-4 space-y-1 text-sm">
                <template x-for="(n,i) in result.notes" :key="i">
                    <li class="flex gap-2"><span class="text-gold-600">•</span><span x-text="n"></span></li>
                </template>
            </ul>
        </template>

        {{-- Parsed result --}}
        <template x-if="result && result.parsed">
            <div class="mb-4">
                <div class="flex items-center justify-between mb-1">
                    <h3 class="text-sm font-semibold">Parsed result</h3>
                    <button type="button" @click="copy(result.parsed)" class="text-xs text-gold-700 hover:underline">Copy</button>
                </div>
                <pre class="bg-ink-900 text-green-200 text-xs rounded-lg p-3 overflow-x-auto max-h-72" x-text="pretty(result.parsed)"></pre>
            </div>
        </template>

        {{-- Each HTTP call --}}
        <h3 class="text-sm font-semibold mb-2" x-show="result && result.calls && result.calls.length">HTTP calls</h3>
        <div class="space-y-3">
            <template x-for="(c,i) in (result ? result.calls : [])" :key="i">
                <div class="rounded-lg border border-ink-100">
                    <div class="flex items-center justify-between px-3 py-2 bg-ink-50 rounded-t-lg">
                        <div class="min-w-0">
                            <span class="font-mono text-xs" x-text="c.request.method + ' ' + c.request.endpoint"></span>
                            <span class="text-[11px] text-ink-700/50" x-text="'· ' + c.duration_ms + 'ms'"></span>
                        </div>
                        <span class="badge shrink-0" :class="(c.error || c.http_status>=400 || c.http_status===0) ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'" x-text="'HTTP ' + c.http_status"></span>
                    </div>
                    <div class="p-3 space-y-2">
                        <div class="text-[11px] text-ink-700/50 font-mono break-all" x-text="c.request.url"></div>
                        <template x-if="c.error">
                            <pre class="bg-red-50 text-red-800 text-xs rounded p-2 overflow-x-auto" x-text="pretty(c.error)"></pre>
                        </template>
                        <template x-if="c.exception">
                            <pre class="bg-red-50 text-red-800 text-[11px] rounded p-2 overflow-x-auto max-h-48" x-text="c.exception.message + '\n\n' + c.exception.trace"></pre>
                        </template>
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-ink-700/50">Raw JSON response</span>
                            <button type="button" @click="copy(c.raw)" class="text-xs text-gold-700 hover:underline">Copy</button>
                        </div>
                        <pre class="bg-ink-900 text-ink-100 text-xs rounded p-2 overflow-x-auto max-h-72" x-text="pretty(c.raw)"></pre>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- ── Latest buffered activity ────────────────────────────────────────── --}}
    <div class="grid lg:grid-cols-2 gap-4">
        <div class="card p-5">
            <div class="flex items-center justify-between mb-2">
                <h2 class="font-semibold">Latest API requests / responses</h2>
                <form action="{{ route('admin.meta.debug.clear') }}" method="POST" onsubmit="return confirm('Clear the debug buffer?')">@csrf<button class="text-xs text-red-600 hover:underline">Clear</button></form>
            </div>
            @forelse($recent as $r)
                <details class="border-b border-ink-50 py-1.5">
                    <summary class="text-xs cursor-pointer flex items-center justify-between gap-2">
                        <span class="font-mono truncate">{{ ($r['method'] ?? '') }} {{ $r['endpoint'] ?? '' }}</span>
                        <span class="shrink-0 {{ ($r['is_error'] ?? false) ? 'text-red-600' : 'text-ink-700/50' }}">HTTP {{ $r['http_status'] ?? '—' }} · {{ $r['duration_ms'] ?? 0 }}ms</span>
                    </summary>
                    <pre class="bg-ink-900 text-ink-100 text-[11px] rounded p-2 mt-1 overflow-x-auto max-h-64">{{ json_encode($r['response'] ?? $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </details>
            @empty
                <p class="text-sm text-ink-700/50">No requests recorded yet. Run a test above.</p>
            @endforelse
        </div>

        <div class="card p-5">
            <h2 class="font-semibold mb-2">Latest errors</h2>
            @forelse($errors as $e)
                <details class="border-b border-ink-50 py-1.5">
                    <summary class="text-xs cursor-pointer font-mono text-red-700 truncate">{{ ($e['method'] ?? '') }} {{ $e['endpoint'] ?? '' }} — {{ $e['graph_error']['message'] ?? 'error' }}</summary>
                    <pre class="bg-red-50 text-red-800 text-[11px] rounded p-2 mt-1 overflow-x-auto max-h-64">{{ json_encode($e['graph_error'] ?? $e['exception'] ?? $e, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </details>
            @empty
                <p class="text-sm text-ink-700/50">No errors recorded.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
