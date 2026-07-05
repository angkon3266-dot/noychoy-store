@extends('layouts.admin')
@section('title', 'Meta Connection')
@section('heading', 'Marketing')

@section('content')
<div class="max-w-4xl space-y-6">
    <a href="{{ route('admin.marketing.index') }}" class="text-sm text-ink-700/50 hover:text-gold-700">← Marketing Center</a>
    <h2 class="font-display text-xl font-semibold">Meta Connection</h2>

    @unless($oauthConfigured)
        <div class="rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm">
            To connect with Facebook, set your <strong>Meta App ID &amp; Secret</strong> in
            <a href="{{ route('admin.system-config.edit', 'meta') }}" class="underline">System Config → Meta</a>,
            and whitelist this OAuth redirect URI in your Meta App:
            <code class="break-all block mt-1">{{ route('admin.meta.connection.callback') }}</code>
        </div>
    @endunless

    {{-- Connection status --}}
    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="text-sm space-y-1.5">
                <h3 class="font-semibold mb-1">Status</h3>
                <p>Connection:
                    @php $badge = ['ok'=>'bg-green-100 text-green-700','expiring'=>'bg-amber-100 text-amber-700','expired'=>'bg-red-100 text-red-700','needs_reconnect'=>'bg-red-100 text-red-700','disconnected'=>'bg-ink-100 text-ink-700'][$health] ?? 'bg-ink-100 text-ink-700'; @endphp
                    <span class="badge {{ $badge }}">{{ ucfirst(str_replace('_',' ',$health)) }}</span>
                </p>
                @if($businessId)<p>Business ID: <span class="font-medium">{{ $businessId }}</span></p>@endif
                <p>Granted permissions: <span class="text-ink-700/60">{{ $scopes ? implode(', ', $scopes) : '—' }}</span></p>
            </div>
            @if($connected)
                <form action="{{ route('admin.meta.connection.disconnect') }}" method="POST" onsubmit="return confirm('Disconnect Meta? Modules will need re-authorizing.')">@csrf
                    <button class="btn-outline text-red-600">Disconnect</button>
                </form>
            @endif
        </div>

        {{-- Discovered assets --}}
        @if($connected)
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-4 text-sm">
                @foreach(['catalog'=>'Catalogs','page'=>'Pages','instagram'=>'Instagram','ad_account'=>'Ad accounts'] as $type=>$label)
                    <div class="rounded-lg border border-ink-100 p-3">
                        <div class="text-xs text-ink-700/50">{{ $label }}</div>
                        @forelse($assets[$type] as $a)
                            <div class="truncate">{{ $a['name'] ?: $a['id'] }}</div>
                        @empty
                            <div class="text-ink-700/40">None</div>
                        @endforelse
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Per-module authorization (modular OAuth) --}}
    <div class="card p-5">
        <h3 class="font-semibold mb-1">Module permissions</h3>
        <p class="text-sm text-ink-700/60 mb-4">Authorize each module separately — you're only asked for the permissions that module needs, when you need them.</p>

        <div class="space-y-2">
            @foreach($modules as $m)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-ink-100 px-4 py-3">
                    <div>
                        <div class="font-medium">{{ $m['name'] }}
                            @unless($m['available'])<span class="badge bg-ink-100 text-ink-700 text-[10px] ml-1">coming soon</span>@endunless
                        </div>
                        <div class="text-xs text-ink-700/50">{{ implode(', ', $m['scopes']) }}</div>
                    </div>
                    <div>
                        @if($m['authorized'])
                            <span class="badge bg-green-100 text-green-700">Authorized</span>
                        @elseif($oauthConfigured)
                            <a href="{{ route('admin.meta.connection.authorize', $m['key']) }}" class="btn-outline text-sm py-1.5">Authorize</a>
                        @else
                            <span class="badge bg-ink-100 text-ink-700">Configure app first</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
