@extends('layouts.admin')
@section('title', 'Meta Sync Logs')
@section('heading', 'Meta Sync Logs')

@section('content')
<div class="space-y-4" x-data="{ open: null }">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex flex-wrap gap-2">
            <input name="q" value="{{ request('q') }}" placeholder="Search product, retailer id, error…" class="input py-2 w-64">
            <select name="status" onchange="this.form.submit()" class="input py-2">
                <option value="">All status</option>
                @foreach($statuses as $s)<option value="{{ $s }}" @selected(request('status')===$s)>{{ ucfirst($s) }}</option>@endforeach
            </select>
            <select name="action" onchange="this.form.submit()" class="input py-2">
                <option value="">All actions</option>
                @foreach($actions as $a)<option value="{{ $a }}" @selected(request('action')===$a)>{{ ucfirst($a) }}</option>@endforeach
            </select>
            <button class="btn-outline">Filter</button>
        </form>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.meta.index') }}" class="btn-outline">← Back</a>
            @if($failedCount > 0)
                <form action="{{ route('admin.meta.logs.retry') }}" method="POST">@csrf<button class="btn-primary">Retry {{ $failedCount }} failed</button></form>
            @endif
        </div>
    </div>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm min-w-[820px]">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr>
                    <th class="px-4 py-3">Product</th>
                    <th class="px-4 py-3">Action</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Retries</th>
                    <th class="px-4 py-3">Time</th>
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($logs as $log)
                    <tr class="hover:bg-ink-50/60">
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $log->product_name ?? '—' }}</div>
                            <div class="text-xs text-ink-700/40">{{ $log->retailer_id }}</div>
                        </td>
                        <td class="px-4 py-3 capitalize">{{ str_replace('_', ' ', $log->action) }}</td>
                        <td class="px-4 py-3">
                            <span class="badge {{ $log->status==='success' ? 'bg-green-100 text-green-700' : ($log->status==='failed' ? 'bg-red-100 text-red-700' : 'bg-ink-100 text-ink-700') }}">{{ ucfirst($log->status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-ink-700/70">{{ $log->retry_count }}</td>
                        <td class="px-4 py-3 text-ink-700/70">{{ $log->execution_ms !== null ? $log->execution_ms.' ms' : '—' }}</td>
                        <td class="px-4 py-3 text-ink-700/60">{{ $log->created_at->format('d M H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            @if($log->api_error || $log->meta_response)
                                <button type="button" class="text-gold-700 hover:underline text-xs" @click="open = (open === {{ $log->id }} ? null : {{ $log->id }})">Details</button>
                            @endif
                        </td>
                    </tr>
                    @if($log->api_error || $log->meta_response)
                        <tr x-show="open === {{ $log->id }}" x-cloak>
                            <td colspan="7" class="px-4 py-3 bg-ink-50/50">
                                @if($log->api_error)<div class="mb-2"><span class="text-xs font-medium text-red-700">API error:</span><pre class="text-xs whitespace-pre-wrap text-red-800">{{ $log->api_error }}</pre></div>@endif
                                @if($log->meta_response)<div><span class="text-xs font-medium text-ink-700/60">Meta response:</span><pre class="text-xs whitespace-pre-wrap text-ink-700/70 max-h-48 overflow-y-auto">{{ json_encode($log->meta_response, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre></div>@endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-ink-700/50">No sync activity yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $logs->links() }}</div>
</div>
@endsection
