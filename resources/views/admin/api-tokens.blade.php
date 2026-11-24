@extends('layouts.admin')
@section('title', 'Claude connector')
@section('heading', 'Claude connector')

@section('content')
<div class="max-w-5xl space-y-6" x-data="{ tab: 'web', copied: null, copy(text, key) { navigator.clipboard.writeText(text); this.copied = key; setTimeout(() => this.copied = null, 2000) } }">

    @if(session('new_api_token'))
        <div class="card p-5 border-2 border-gold-400 bg-gold-50">
            <h2 class="font-semibold mb-1">Your new token</h2>
            <p class="text-sm text-ink-700/70 mb-3">Copy it now. It is stored only as a hash — reopening this page will not show it again.</p>
            <div class="flex gap-2">
                <input readonly value="{{ session('new_api_token') }}" class="input font-mono text-sm bg-white" onclick="this.select()">
                <button type="button" class="btn-primary whitespace-nowrap" @click="copy(@js(session('new_api_token')), 'tok')">
                    <span x-text="copied === 'tok' ? 'Copied ✓' : 'Copy'"></span>
                </button>
            </div>
            <p class="text-xs text-ink-700/60 mt-3 font-mono break-all">claude mcp add --transport http {{ Str::slug(store_name()) }} {{ $mcpUrl }} --header "Authorization: Bearer {{ session('new_api_token') }}"</p>
        </div>
    @endif

    {{-- 1. What it is + the URL --}}
    <div class="card p-6">
        <h2 class="font-semibold mb-1">Run this store from Claude</h2>
        <p class="text-sm text-ink-700/70 mb-4">
            Connect Claude and ask it in plain words: “add these three rings as drafts”, “what sold this week?”,
            “cancel order 1042, the customer called”, “hide the 1-star review on the pearl studs”.
            It works through the same rules as this admin panel, as the person who connected it — and only in the
            sections that person’s role can open. It asks before deleting anything, changing an order or texting a customer,
            and every action is listed at the bottom of this page.
        </p>
        <label class="label">Connector URL</label>
        <div class="flex gap-2">
            <input readonly value="{{ $mcpUrl }}" class="input font-mono text-sm" onclick="this.select()">
            <button type="button" class="btn-outline whitespace-nowrap" @click="copy(@js($mcpUrl), 'url')">
                <span x-text="copied === 'url' ? 'Copied ✓' : 'Copy'"></span>
            </button>
        </div>
    </div>

    {{-- 2. Setup per Claude app --}}
    <div class="card p-6">
        <h2 class="font-semibold mb-3">Connect it</h2>
        <div class="flex flex-wrap gap-2 mb-4 text-sm">
            <button type="button" @click="tab = 'web'" :class="tab === 'web' ? 'btn-primary' : 'btn-outline'">claude.ai &amp; Claude Desktop</button>
            <button type="button" @click="tab = 'code'" :class="tab === 'code' ? 'btn-primary' : 'btn-outline'">Claude Code</button>
        </div>

        <div x-show="tab === 'web'" class="text-sm text-ink-700 space-y-2">
            <ol class="list-decimal pl-5 space-y-1.5">
                <li>In Claude, open <strong>Settings → Connectors</strong> and choose <strong>Add custom connector</strong>.</li>
                <li>Name it “{{ store_name() }}” and paste the connector URL above. Leave the advanced OAuth fields empty.</li>
                <li>Click <strong>Connect</strong>. A page from this store opens — log in to the admin if asked, check the name it shows, and click <strong>Allow</strong>.</li>
                <li>In a chat, turn the connector on from the tools menu and ask away.</li>
            </ol>
            <p class="text-xs text-ink-700/60">
                No token to copy: you sign in with your own admin account, and Claude works as you. The connection appears in the list below as
                “Claude (connected …)” and can be disconnected there. On Team/Enterprise plans an organisation owner adds custom connectors first.
                Claude Desktop shares claude.ai’s connectors once you are signed in.
            </p>
        </div>

        <div x-show="tab === 'code'" x-cloak class="text-sm text-ink-700 space-y-2">
            <p>Create a token below, then run once in a terminal:</p>
            <pre class="rounded-lg bg-ink-900 text-gold-100 p-4 font-mono text-xs overflow-x-auto">claude mcp add --transport http {{ Str::slug(store_name()) }} {{ $mcpUrl }} --header "Authorization: Bearer YOUR_TOKEN"</pre>
            <p class="text-xs text-ink-700/60">
                Claude Code can also use the sign-in flow instead of a token: run the command without <code>--header</code>, then <code>/mcp</code> → Authenticate.
            </p>
        </div>
    </div>

    {{-- 3. Connections --}}
    <div class="card overflow-hidden">
        <div class="p-6 pb-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="font-semibold">Connections</h2>
                <p class="text-xs text-ink-700/60">Each acts as the admin user shown, with that user’s role. Disconnecting takes effect immediately.</p>
            </div>
            <form method="POST" action="{{ route('admin.api-tokens.store') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <div>
                    <label class="label">New token name</label>
                    <input name="name" class="input" placeholder="Claude Code" required maxlength="60">
                </div>
                <div class="w-32">
                    <label class="label">Expires (days)</label>
                    <input name="expires_days" type="number" min="1" max="3650" class="input" placeholder="never">
                </div>
                <button class="btn-primary">Create token</button>
            </form>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr>
                    <th class="px-4 py-3">Name</th><th class="px-4 py-3">Acts as</th><th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Last used</th><th class="px-4 py-3">Expires</th><th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($tokens as $t)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $t->name }}<div class="font-mono text-xs text-ink-700/50">{{ $t->prefix }}…</div></td>
                        <td class="px-4 py-3">
                            @if($t->user)
                                {{ $t->user->name }} <span class="text-xs text-ink-700/50">({{ $t->user->role }})</span>
                            @else
                                <span class="text-red-600 text-xs">no user — will not work</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-ink-700/70">{{ $t->kind === 'oauth' ? 'Signed in (claude.ai)' : 'Token' }}</td>
                        <td class="px-4 py-3 text-ink-700/60">{{ $t->last_used_at?->diffForHumans() ?? 'never' }}</td>
                        <td class="px-4 py-3 text-ink-700/60">
                            @if($t->kind === 'oauth')
                                renews itself
                            @elseif($t->expires_at)
                                <span class="{{ $t->expires_at->isPast() ? 'text-red-600' : '' }}">{{ $t->expires_at->toFormattedDateString() }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.api-tokens', ['token' => $t->id]) }}#activity" class="text-sm text-ink-700/70 hover:underline mr-3">Activity</a>
                            <form method="POST" action="{{ route('admin.api-tokens.destroy', $t) }}" class="inline"
                                  onsubmit="return confirm(@js('Disconnect “'.$t->name.'”? Anything using it stops working immediately.'))">
                                @csrf @method('DELETE')
                                <button class="text-sm text-red-600 hover:underline">Disconnect</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-ink-700/50">Nothing connected yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 4. Audit log --}}
    <div class="card overflow-hidden" id="activity">
        <div class="p-6 pb-4 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 class="font-semibold">What Claude did</h2>
                <p class="text-xs text-ink-700/60">
                    Every tool call, newest first — {{ number_format($callsToday) }} in the last 24 hours{{ $failedToday ? ', '.$failedToday.' refused or failed' : '' }}.
                    Kept for a year.
                </p>
            </div>
            @if($tokenFilter)
                <a href="{{ route('admin.api-tokens') }}#activity" class="text-sm hover:underline">Show all connections</a>
            @endif
        </div>
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">When</th><th class="px-4 py-3">Tool</th><th class="px-4 py-3">Details</th><th class="px-4 py-3">By</th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100 align-top">
                @forelse($calls as $c)
                    <tr>
                        <td class="px-4 py-2.5 whitespace-nowrap text-ink-700/60" title="{{ $c->created_at?->timezone(config('store.timezone', 'Asia/Dhaka')) }}">{{ $c->created_at?->diffForHumans() }}</td>
                        <td class="px-4 py-2.5 whitespace-nowrap">
                            <span class="font-mono text-xs">{{ $c->tool }}</span>
                            @unless($c->ok)<span class="ml-1 rounded bg-red-50 text-red-700 text-[10px] px-1.5 py-0.5">refused</span>@endunless
                        </td>
                        <td class="px-4 py-2.5 text-xs text-ink-700/70 break-all">
                            @if($c->arguments)<div class="font-mono">{{ Str::limit(json_encode($c->arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 220) }}</div>@endif
                            @if($c->error)<div class="text-red-700 mt-0.5">{{ $c->error }}</div>@endif
                        </td>
                        <td class="px-4 py-2.5 text-xs whitespace-nowrap">{{ $c->user?->name ?? '—' }}<div class="text-ink-700/50">{{ $c->token_name }}</div></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-ink-700/50">Claude has not done anything here yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        @if($calls->hasPages())<div class="p-4">{{ $calls->links() }}</div>@endif
    </div>
</div>
@endsection
