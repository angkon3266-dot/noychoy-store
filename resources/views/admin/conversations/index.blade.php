@extends('layouts.admin')
@section('title', 'Chat history')
@section('heading', 'Chat history')

@section('content')
<p class="text-sm text-ink-700/70 mb-4">
    Every conversation customers have had with the storefront assistant. Read these to see what people
    are actually looking for — and which questions the assistant could not answer.
</p>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
    <div class="card p-4">
        <p class="text-xs uppercase tracking-wide text-ink-700/50">Conversations</p>
        <p class="text-2xl font-semibold mt-1">{{ number_format($totals['all']) }}</p>
    </div>
    <div class="card p-4">
        <p class="text-xs uppercase tracking-wide text-ink-700/50">Last 7 days</p>
        <p class="text-2xl font-semibold mt-1">{{ number_format($totals['week']) }}</p>
    </div>
    <div class="card p-4">
        <p class="text-xs uppercase tracking-wide text-ink-700/50">Assistant couldn’t answer</p>
        <p class="text-2xl font-semibold mt-1 {{ $totals['failed'] ? 'text-amber-700' : '' }}">{{ number_format($totals['failed']) }}</p>
    </div>
</div>

<div class="flex flex-wrap items-center gap-2 mb-4">
    <form method="GET" class="flex flex-wrap gap-2">
        @if($filter)<input type="hidden" name="filter" value="{{ $filter }}">@endif
        <input name="q" value="{{ $q }}" placeholder="Search what was said…" class="input py-2 w-64">
        <button class="btn-outline py-2 text-sm">Search</button>
        @if($q)<a href="{{ route('admin.conversations.index', ['filter' => $filter ?: null]) }}" class="btn-outline py-2 text-sm">Clear</a>@endif
    </form>
    <div class="ml-auto flex flex-wrap gap-2">
        @foreach(['' => 'All', 'failed' => 'Couldn’t answer', 'members' => 'Members'] as $key => $label)
            <a href="{{ route('admin.conversations.index', array_filter(['filter' => $key ?: null, 'q' => $q ?: null])) }}"
               class="px-3 py-1.5 rounded-full text-sm {{ (string) $filter === (string) $key ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700' }}">{{ $label }}</a>
        @endforeach
    </div>
</div>

@if($conversations->isEmpty())
    <div class="card p-10 text-center text-ink-700/60">
        @if($q || $filter)
            <p>Nothing matched.</p>
        @else
            <p>No conversations yet.</p>
            <p class="text-xs mt-1">They appear here as soon as someone talks to the assistant on the storefront.</p>
        @endif
    </div>
@else
    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr>
                    <th class="px-4 py-2.5">Opened with</th>
                    <th class="px-4 py-2.5">Who</th>
                    <th class="px-4 py-2.5 text-center">Messages</th>
                    <th class="px-4 py-2.5">Last message</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @foreach($conversations as $c)
                    <tr class="hover:bg-gold-50/50">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.conversations.show', $c) }}" class="text-gold-700 hover:underline">
                                {{ \Illuminate\Support\Str::limit($openers[$c->id] ?? '(no question recorded)', 90) }}
                            </a>
                            @if($c->had_failure)
                                <span class="badge bg-amber-100 text-amber-800 ml-1">couldn’t answer</span>
                            @endif
                            @if($c->last_page)
                                <div class="text-xs text-ink-700/40">from {{ $c->last_page }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($c->customer)
                                <a href="{{ route('admin.customers.show', $c->customer) }}" class="hover:underline">{{ $c->customer->name }}</a>
                                <div class="text-xs text-ink-700/45">{{ $c->customer->phone }}</div>
                            @else
                                <span class="text-ink-700/50">Guest</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">{{ $c->message_count }}</td>
                        <td class="px-4 py-3 text-ink-700/70">
                            {{ $c->last_message_at?->diffForHumans() }}
                            <div class="text-xs text-ink-700/40">{{ $c->last_message_at?->format('d M Y, g:i a') }}</div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $conversations->links() }}</div>
@endif
@endsection
