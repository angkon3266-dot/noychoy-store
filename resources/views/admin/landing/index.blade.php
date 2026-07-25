@extends('layouts.admin')
@section('title', 'Landing pages')
@section('heading', 'Landing pages')

@section('content')
<div class="flex items-center justify-between mb-4">
    <p class="text-sm text-ink-700/70 max-w-2xl">
        Standalone pages for ad campaigns — built from blocks, with hero CTAs, countdowns, benefits,
        FAQs and a sticky buy bar. Send Facebook traffic straight here instead of the homepage.
    </p>
    <a href="{{ route('admin.landing.create') }}" class="btn-primary whitespace-nowrap">+ New landing page</a>
</div>

<div class="card overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr>
                <th class="px-4 py-3">Page</th>
                <th class="px-4 py-3">URL</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Views</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($pages as $p)
                <tr class="border-b border-ink-100 hover:bg-ink-50">
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.landing.edit', $p) }}" class="font-medium hover:text-gold-700">{{ $p->title }}</a>
                        <div class="text-xs text-ink-700/50">{{ count($p->blocks ?? []) }} section(s) · {{ count($p->product_ids ?? []) }} product(s)</div>
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ $p->url() }}" target="_blank" class="text-gold-700 hover:underline">/lp/{{ $p->slug }}</a>
                    </td>
                    <td class="px-4 py-3">
                        <span class="badge {{ $p->is_published ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-600' }}">
                            {{ $p->is_published ? 'Live' : 'Draft' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-ink-700/70">{{ number_format($p->views) }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <form action="{{ route('admin.landing.duplicate', $p) }}" method="POST" class="inline">
                            @csrf
                            <button class="text-xs text-gold-700 hover:underline">Duplicate</button>
                        </form>
                        <form action="{{ route('admin.landing.destroy', $p) }}" method="POST" class="inline ml-2"
                              onsubmit="return confirm('Delete this landing page? This cannot be undone.')">
                            @csrf @method('DELETE')
                            <button class="text-xs text-red-600 hover:underline">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-12 text-center text-ink-700/50">
                    No landing pages yet — create one for your next campaign.
                </td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $pages->links() }}</div>
@endsection
