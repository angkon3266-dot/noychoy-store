@extends('layouts.admin')
@section('title', 'API tokens')
@section('heading', 'API tokens')

@section('content')
<div class="max-w-4xl space-y-6">

    @if(session('new_api_token'))
        <div class="card p-5 border-2 border-gold-400 bg-gold-50" x-data="{ copied: false }">
            <h2 class="font-semibold mb-1">Your new token</h2>
            <p class="text-sm text-ink-700/70 mb-3">Copy it now. It is stored only as a hash — reopening this page will not show it again.</p>
            <div class="flex gap-2">
                <input readonly value="{{ session('new_api_token') }}" x-ref="tok"
                       class="input font-mono text-sm bg-white" onclick="this.select()">
                <button type="button" class="btn-primary whitespace-nowrap"
                        @click="navigator.clipboard.writeText($refs.tok.value); copied = true; setTimeout(() => copied = false, 2000)">
                    <span x-text="copied ? 'Copied ✓' : 'Copy'"></span>
                </button>
            </div>
        </div>
    @endif

    <div class="card p-6">
        <h2 class="font-semibold mb-1">Connect Claude to this store</h2>
        <p class="text-sm text-ink-700/70 mb-4">
            This store speaks <strong>MCP</strong> — the same protocol Claude uses to work a Shopify store. Create a token below,
            then add this as a custom connector in Claude and it can search your catalogue, upload product photos, set the
            main image and edit product details.
        </p>
        <div class="rounded-lg bg-ink-900 text-gold-100 p-4 font-mono text-xs space-y-1 overflow-x-auto">
            <div><span class="text-gold-400">Connector URL</span>  {{ $mcpUrl }}</div>
            <div><span class="text-gold-400">Auth</span>           Bearer &lt;your token&gt;</div>
        </div>
        <p class="text-xs text-ink-700/50 mt-3">
            Uploads take an image URL: the store downloads it, converts to WebP, applies your watermark if one is set,
            and generates the responsive variant — exactly as an upload through Products would.
        </p>
    </div>

    <div class="card p-6">
        <h2 class="font-semibold mb-4">Create a token</h2>
        <form method="POST" action="{{ route('admin.api-tokens.store') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex-1 min-w-48">
                <label class="label">Name</label>
                <input name="name" class="input" placeholder="Claude" required maxlength="60">
            </div>
            <div class="w-40">
                <label class="label">Expires in (days)</label>
                <input name="expires_days" type="number" min="1" max="3650" class="input" placeholder="never">
            </div>
            <button class="btn-primary">Create token</button>
        </form>
    </div>

    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr>
                    <th class="px-4 py-3">Name</th><th class="px-4 py-3">Token</th>
                    <th class="px-4 py-3">Last used</th><th class="px-4 py-3">Expires</th><th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($tokens as $t)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $t->name }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-ink-700/60">{{ $t->prefix }}…</td>
                        <td class="px-4 py-3 text-ink-700/60">{{ $t->last_used_at?->diffForHumans() ?? 'never' }}</td>
                        <td class="px-4 py-3 text-ink-700/60">
                            @if($t->expires_at)
                                <span class="{{ $t->expires_at->isPast() ? 'text-red-600' : '' }}">{{ $t->expires_at->toFormattedDateString() }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ route('admin.api-tokens.destroy', $t) }}"
                                  onsubmit="return confirm('Revoke “{{ $t->name }}”? Anything using it stops working immediately.')">
                                @csrf @method('DELETE')
                                <button class="text-sm text-red-600 hover:underline">Revoke</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-ink-700/50">No tokens yet. Create one above to connect Claude.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
