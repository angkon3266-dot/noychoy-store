@extends('layouts.admin')
@section('title', 'Compare Versions')
@section('heading', 'System Configuration')

@section('content')
<div class="max-w-3xl space-y-4">
    @include('admin.system-config._nav')
    <a href="{{ route('admin.system-config.history') }}" class="text-sm text-gold-700 hover:underline">← History</a>

    <div class="grid grid-cols-2 gap-3 text-sm">
        <div class="card p-3"><div class="text-xs text-ink-700/50">Version A</div>#{{ $a->id }} · {{ $a->created_at->format('d M H:i') }} · {{ $a->user_name }}</div>
        <div class="card p-3"><div class="text-xs text-ink-700/50">Version B</div>#{{ $b->id }} · {{ $b->created_at->format('d M H:i') }} · {{ $b->user_name }}</div>
    </div>

    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Field</th><th class="px-4 py-3">A</th><th class="px-4 py-3">B</th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @foreach($rows as $r)
                    <tr class="{{ $r['changed'] ? 'bg-amber-50/40' : '' }}">
                        <td class="px-4 py-3 font-medium">{{ $r['label'] }}</td>
                        <td class="px-4 py-3 {{ $r['changed'] ? 'text-red-700' : 'text-ink-700/70' }}">{{ $r['old'] }}</td>
                        <td class="px-4 py-3 {{ $r['changed'] ? 'text-green-700' : 'text-ink-700/70' }}">{{ $r['new'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
