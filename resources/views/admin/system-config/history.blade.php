@extends('layouts.admin')
@section('title', 'Configuration History')
@section('heading', 'System Configuration')

@section('content')
<div class="max-w-4xl space-y-4" x-data="{ sel: [] }">
    @include('admin.system-config._nav')

    <div class="flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap gap-2 items-end">
            <div><label class="label text-xs">Search</label><input name="q" value="{{ request('q') }}" placeholder="User, notes, section" class="input py-2 w-48"></div>
            <div><label class="label text-xs">User</label>
                <select name="user" onchange="this.form.submit()" class="input py-2">
                    <option value="">All</option>
                    @foreach($users as $u)<option value="{{ $u->id }}" @selected(request('user')==$u->id)>{{ $u->name }}</option>@endforeach
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
        <a href="{{ route('admin.system-config.history.download') }}" class="btn-outline">Download CSV</a>
    </div>

    {{-- Compare bar --}}
    <form method="GET" action="{{ route('admin.system-config.history.compare') }}" x-show="sel.length >= 1" x-cloak
          class="flex items-center gap-3 rounded-lg bg-gold-50 border border-gold-200 px-4 py-2.5 text-sm">
        <span><span x-text="sel.length"></span> selected (pick 2 to compare)</span>
        <input type="hidden" name="a" :value="sel[0]">
        <input type="hidden" name="b" :value="sel[1]">
        <button class="btn-primary text-sm py-1.5" :disabled="sel.length !== 2">Compare</button>
        <button type="button" class="text-ink-700/50 hover:underline" @click="sel = []">Clear</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm min-w-[720px]">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-3 py-3 w-8"></th><th class="px-4 py-3">Date</th><th class="px-4 py-3">User</th><th class="px-4 py-3">Module</th><th class="px-4 py-3">Notes</th><th class="px-4 py-3">IP</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($versions as $v)
                    <tr class="hover:bg-ink-50/60">
                        <td class="px-3 py-3"><input type="checkbox" value="{{ $v->id }}" x-model.number="sel" :disabled="sel.length >= 2 && !sel.includes({{ $v->id }})"></td>
                        <td class="px-4 py-3 text-ink-700/70">{{ $v->created_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3">{{ $v->user_name ?? '—' }}</td>
                        <td class="px-4 py-3 capitalize">{{ $v->section }}</td>
                        <td class="px-4 py-3 text-ink-700/60">{{ $v->notes ?: '—' }}</td>
                        <td class="px-4 py-3 text-ink-700/40 text-xs">{{ $v->ip }}</td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('admin.system-config.history.show', $v) }}" class="text-gold-700 hover:underline text-xs">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-ink-700/50">No configuration changes recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $versions->links() }}</div>
</div>
@endsection
