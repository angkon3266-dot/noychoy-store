@extends('layouts.admin')
@section('title', 'Backup & Restore')
@section('heading', 'System Configuration')

@section('content')
<div class="max-w-4xl space-y-5">
    @include('admin.system-config._nav')

    <div class="grid md:grid-cols-3 gap-4">
        {{-- Create backup --}}
        <form action="{{ route('admin.system-config.backups.store') }}" method="POST" class="card p-4 space-y-3">
            @csrf
            <h3 class="font-semibold text-sm">Create backup</h3>
            <input name="name" class="input py-2" placeholder="Backup name (optional)">
            <button class="btn-primary w-full text-sm">Create backup</button>
            <p class="text-[11px] text-ink-700/50">A full encrypted snapshot of all settings.</p>
        </form>

        {{-- Export --}}
        <form action="{{ route('admin.system-config.export') }}" method="GET" class="card p-4 space-y-3" x-data="{ all: true }">
            <h3 class="font-semibold text-sm">Export</h3>
            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="all"> All sections</label>
            <div x-show="!all" x-cloak class="max-h-28 overflow-y-auto space-y-1">
                @foreach($sections as $skey => $s)
                    <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="sections[]" value="{{ $skey }}"> {{ $s['label'] }}</label>
                @endforeach
            </div>
            <button class="btn-outline w-full text-sm">Download encrypted export</button>
        </form>

        {{-- Import --}}
        <form action="{{ route('admin.system-config.import.preview') }}" method="POST" enctype="multipart/form-data" class="card p-4 space-y-3">
            @csrf
            <h3 class="font-semibold text-sm">Import</h3>
            <input type="file" name="backup_file" class="input py-2 text-xs" accept=".json,.txt" required>
            <button class="btn-outline w-full text-sm">Preview import</button>
            <p class="text-[11px] text-ink-700/50">Upload an encrypted export from this platform.</p>
        </form>
    </div>

    {{-- Backup list --}}
    <div class="card overflow-x-auto">
        <table class="w-full text-sm min-w-[720px]">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Created</th><th class="px-4 py-3">By</th><th class="px-4 py-3">Size</th><th class="px-4 py-3">Modules</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($backups as $b)
                    <tr class="hover:bg-ink-50/60">
                        <td class="px-4 py-3">
                            {{ $b->name }}
                            @if($b->is_auto)<span class="badge bg-ink-100 text-ink-700 text-[10px] ml-1">auto</span>@endif
                        </td>
                        <td class="px-4 py-3 text-ink-700/70">{{ $b->created_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3">{{ $b->creator_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-700/60">{{ $b->humanSize() }}</td>
                        <td class="px-4 py-3 text-xs text-ink-700/60">{{ implode(', ', $b->modules ?? []) ?: '—' }}</td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('admin.system-config.backups.restore-preview', $b) }}" class="text-gold-700 hover:underline text-xs">Restore</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-ink-700/50">No backups yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $backups->links() }}</div>
</div>
@endsection
