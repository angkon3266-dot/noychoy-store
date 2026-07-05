@extends('layouts.admin')
@section('title', 'Pages')
@section('heading', 'Content Pages')

@section('content')
<div class="max-w-3xl space-y-5">
    <div class="flex items-center justify-between">
        <p class="text-sm text-ink-700/60">Edit the footer legal pages. HTML is allowed (headings, lists, links).</p>
        <a href="{{ route('admin.messages') }}" class="btn-outline text-sm">
            Contact messages @if($unread)<span class="badge bg-gold-600 text-white ml-1">{{ $unread }}</span>@endif
        </a>
    </div>

    <form action="{{ route('admin.pages.update') }}" method="POST" class="space-y-5">
        @csrf
        @foreach(['privacy' => 'Privacy Policy', 'terms' => 'Terms & Conditions', 'refund' => 'Refund & Return Policy'] as $key => $label)
            <div class="card p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold">{{ $label }}</h2>
                    <a href="{{ route('page.'.$key) }}" target="_blank" class="text-xs text-gold-700 hover:underline">View ↗</a>
                </div>
                <div>
                    <label class="label">Title</label>
                    <input name="pages[{{ $key }}][title]" value="{{ $pages[$key]['title'] ?? '' }}" class="input">
                </div>
                <div>
                    <label class="label">Content (HTML)</label>
                    <textarea name="pages[{{ $key }}][body]" rows="8" class="input font-mono text-xs">{{ $pages[$key]['body'] ?? '' }}</textarea>
                </div>
            </div>
        @endforeach

        <div class="card p-5 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Contact page</h2>
                <a href="{{ route('page.contact') }}" target="_blank" class="text-xs text-gold-700 hover:underline">View ↗</a>
            </div>
            <div>
                <label class="label">Title</label>
                <input name="pages[contact][title]" value="{{ $pages['contact']['title'] ?? '' }}" class="input">
            </div>
            <div>
                <label class="label">Intro text</label>
                <textarea name="pages[contact][intro]" rows="2" class="input">{{ $pages['contact']['intro'] ?? '' }}</textarea>
            </div>
            <p class="text-xs text-ink-700/50">Contact details (phone/email/address/WhatsApp) come from your store settings &amp; appearance.</p>
        </div>

        <button class="btn-primary">Save pages</button>
    </form>
</div>
@endsection
