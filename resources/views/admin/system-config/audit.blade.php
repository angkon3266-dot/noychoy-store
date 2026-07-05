@extends('layouts.admin')
@section('title', 'Configuration Audit Log')
@section('heading', 'System Configuration')

@section('content')
<div class="max-w-4xl space-y-4">
    @include('admin.system-config._nav')

    <form method="GET" class="flex flex-wrap gap-2 items-end">
        <div><label class="label text-xs">User</label>
            <select name="user" onchange="this.form.submit()" class="input py-2">
                <option value="">All</option>
                @foreach($users as $u)<option value="{{ $u->id }}" @selected(request('user')==$u->id)>{{ $u->name }}</option>@endforeach
            </select>
        </div>
        <div><label class="label text-xs">Action</label>
            <select name="action" onchange="this.form.submit()" class="input py-2">
                <option value="">All</option>
                @foreach($actions as $a)<option value="{{ $a }}" @selected(request('action')===$a)>{{ ucfirst(str_replace('_',' ',$a)) }}</option>@endforeach
            </select>
        </div>
        <div><label class="label text-xs">Module</label>
            <select name="section" onchange="this.form.submit()" class="input py-2">
                <option value="">All</option>
                @foreach($sections as $s)<option value="{{ $s }}" @selected(request('section')===$s)>{{ ucfirst($s) }}</option>@endforeach
            </select>
        </div>
        <div><label class="label text-xs">Date</label><input type="date" name="date" value="{{ request('date') }}" class="input py-2"></div>
        <button class="btn-outline">Filter</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm min-w-[820px]">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Time</th><th class="px-4 py-3">User</th><th class="px-4 py-3">Action</th><th class="px-4 py-3">Module</th><th class="px-4 py-3">Result</th><th class="px-4 py-3">Detail</th><th class="px-4 py-3">IP / Browser</th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($logs as $log)
                    <tr class="hover:bg-ink-50/60 {{ $log->success ? '' : 'bg-red-50/40' }}">
                        <td class="px-4 py-3 text-ink-700/70 whitespace-nowrap">{{ $log->created_at->format('d M H:i') }}</td>
                        <td class="px-4 py-3">{{ $log->user_name ?? '—' }}</td>
                        <td class="px-4 py-3 capitalize">{{ str_replace('_', ' ', $log->action) }}</td>
                        <td class="px-4 py-3 capitalize">{{ $log->section ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="badge {{ $log->success ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ $log->success ? 'Success' : 'Failed' }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-ink-700/60">
                            {{ $log->message }}
                            @if(!empty($log->detail['keys']))<div class="text-ink-700/40">{{ implode(', ', $log->detail['keys']) }}</div>@endif
                        </td>
                        <td class="px-4 py-3 text-xs text-ink-700/40">{{ $log->ip }}<br>{{ \Illuminate\Support\Str::limit($log->user_agent, 40) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-ink-700/50">No audit entries yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $logs->links() }}</div>
</div>
@endsection
