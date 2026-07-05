@extends('layouts.admin')
@section('title', 'Configuration Version')
@section('heading', 'System Configuration')

@section('content')
<div class="max-w-3xl space-y-4">
    @include('admin.system-config._nav')
    <a href="{{ route('admin.system-config.history') }}" class="text-sm text-gold-700 hover:underline">← History</a>

    <div class="card p-5">
        <div class="text-sm text-ink-700/70 space-y-1">
            <p><strong>{{ ucfirst($version->section) }}</strong> changed {{ $version->created_at->format('d M Y H:i') }}</p>
            <p>By {{ $version->user_name ?? '—' }} · IP {{ $version->ip }}</p>
            @if($version->notes)<p>Notes: {{ $version->notes }}</p>@endif
        </div>
    </div>

    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Field</th><th class="px-4 py-3">Before</th><th class="px-4 py-3">After</th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($changes as $c)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $c['label'] }}</td>
                        <td class="px-4 py-3 text-red-700">{{ $c['old'] }}</td>
                        <td class="px-4 py-3 text-green-700">{{ $c['new'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-ink-700/50">No field-level changes recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
