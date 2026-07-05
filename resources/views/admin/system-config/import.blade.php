@extends('layouts.admin')
@section('title', 'Import Configuration')
@section('heading', 'System Configuration')

@section('content')
<div class="max-w-3xl space-y-4">
    @include('admin.system-config._nav')
    <a href="{{ route('admin.system-config.backups') }}" class="text-sm text-gold-700 hover:underline">← Backups</a>

    <div class="rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm">
        ⚠ <strong>Preview import</strong> from “{{ $meta['app'] }}”@if($meta['created_at']) · exported {{ \Illuminate\Support\Carbon::parse($meta['created_at'])->format('d M Y') }}@endif.
        The changes below will be applied. A safety backup is created automatically first.
    </div>

    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Setting</th><th class="px-4 py-3">Current</th><th class="px-4 py-3">After import</th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($changes as $c)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $c['label'] }} <span class="text-xs text-ink-700/40">({{ $c['section'] }})</span></td>
                        <td class="px-4 py-3 text-red-700">{{ $c['old'] }}</td>
                        <td class="px-4 py-3 text-green-700">{{ $c['new'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-ink-700/50">Nothing would change — the import matches the current configuration.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <form action="{{ route('admin.system-config.import') }}" method="POST" class="card p-5 space-y-3"
          onsubmit="return confirm('Apply this imported configuration now?')">
        @csrf
        <input type="hidden" name="payload" value="{{ $payload }}">
        <label class="label">Confirm with your admin password</label>
        <input type="password" name="security_password" class="input" autocomplete="off" required>
        @error('security_password')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        <div class="flex gap-2">
            <button class="btn-primary" @disabled(empty($changes))>Apply import</button>
            <a href="{{ route('admin.system-config.backups') }}" class="btn-outline">Cancel</a>
        </div>
    </form>
</div>
@endsection
