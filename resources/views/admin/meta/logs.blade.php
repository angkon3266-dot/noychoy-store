@extends('layouts.admin')
@section('title', 'Meta Sync Logs')
@section('heading', 'Marketing')

@section('content')
<div class="space-y-4" x-data="{ open: null, sel: [] }">
    @include('admin.meta._nav')

    <div class="flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap gap-2 items-end">
            <div><label class="label text-xs">Search</label><input name="q" value="{{ request('q') }}" placeholder="Product, retailer id, error…" class="input py-2 w-56"></div>
            <div><label class="label text-xs">Status</label>
                <select name="status" onchange="this.form.submit()" class="input py-2">
                    <option value="">All</option>
                    @foreach($statuses as $s)<option value="{{ $s }}" @selected(request('status')===$s)>{{ ucfirst($s) }}</option>@endforeach
                </select>
            </div>
            <div><label class="label text-xs">Action</label>
                <select name="action" onchange="this.form.submit()" class="input py-2">
                    <option value="">All</option>
                    @foreach($actions as $a)<option value="{{ $a }}" @selected(request('action')===$a)>{{ ucfirst(str_replace('_',' ',$a)) }}</option>@endforeach
                </select>
            </div>
            <div><label class="label text-xs">From</label><input type="date" name="date_from" value="{{ request('date_from') }}" class="input py-2"></div>
            <div><label class="label text-xs">To</label><input type="date" name="date_to" value="{{ request('date_to') }}" class="input py-2"></div>
            @if(request('product_id'))<input type="hidden" name="product_id" value="{{ request('product_id') }}">@endif
            <button class="btn-outline">Filter</button>
        </form>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.meta.logs.export', request()->query()) }}" class="btn-outline">Download CSV</a>
            @if($failedCount > 0)
                <form action="{{ route('admin.meta.logs.retry') }}" method="POST">@csrf<button class="btn-primary">Retry {{ $failedCount }} failed</button></form>
            @endif
        </div>
    </div>

    {{-- Retry-selected bar --}}
    <form action="{{ route('admin.meta.logs.retry-selected') }}" method="POST" x-show="sel.length" x-cloak
          class="flex items-center gap-3 rounded-lg bg-gold-50 border border-gold-200 px-4 py-2.5 text-sm">
        @csrf
        <span class="font-medium"><span x-text="sel.length"></span> selected</span>
        <template x-for="id in sel" :key="id"><input type="hidden" name="product_ids[]" :value="id"></template>
        <button class="btn-primary text-sm py-1.5">Retry selected</button>
        <button type="button" class="text-ink-700/50 hover:underline" @click="sel = []">Clear</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm min-w-[860px]">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr>
                    <th class="px-3 py-3 w-8"></th>
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
                        <td class="px-3 py-3">
                            @if($log->product_id)
                                <input type="checkbox" value="{{ $log->product_id }}" x-model.number="sel">
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $log->product_name ?? '—' }}</div>
                            <div class="text-xs text-ink-700/40">{{ $log->retailer_id }}</div>
                        </td>
                        <td class="px-4 py-3 capitalize">{{ str_replace('_', ' ', $log->action) }}</td>
                        <td class="px-4 py-3"><span class="badge {{ $log->status==='success' ? 'bg-green-100 text-green-700' : ($log->status==='failed' ? 'bg-red-100 text-red-700' : 'bg-ink-100 text-ink-700') }}">{{ ucfirst($log->status) }}</span></td>
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
                            <td colspan="8" class="px-4 py-3 bg-ink-50/50">
                                @if($log->api_error)<div class="mb-2"><span class="text-xs font-medium text-red-700">API error:</span><pre class="text-xs whitespace-pre-wrap text-red-800">{{ $log->api_error }}</pre></div>@endif
                                @if($log->meta_response)<div><span class="text-xs font-medium text-ink-700/60">Meta response:</span><pre class="text-xs whitespace-pre-wrap text-ink-700/70 max-h-48 overflow-y-auto">{{ json_encode($log->meta_response, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre></div>@endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-ink-700/50">No sync activity matches these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $logs->links() }}</div>
</div>
@endsection
