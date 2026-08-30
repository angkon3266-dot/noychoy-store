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
        @foreach(['about' => 'Our story (/about)', 'privacy' => 'Privacy Policy', 'terms' => 'Terms & Conditions', 'refund' => 'Refund & Return Policy'] as $key => $label)
            <div class="card p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold">{{ $label }}</h2>
                    <a href="{{ route('page.'.$key) }}" target="_blank" class="text-xs text-gold-700 hover:underline">View ↗</a>
                </div>
                <div>
                    <label class="label">Title</label>
                    <input name="pages[{{ $key }}][title]" value="{{ $pages[$key]['title'] ?? '' }}" class="input">
                </div>
                {{-- The story page has a designed header above the body copy;
                     the legal pages are a title and a column of text. --}}
                @if($key === 'about')
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div>
                            <label class="label">Eyebrow <span class="text-ink-700/40">(small text above the headline)</span></label>
                            <input name="pages[about][eyebrow]" value="{{ $pages['about']['eyebrow'] ?? '' }}" class="input" placeholder="{{ store_name() }}">
                        </div>
                        <div>
                            <label class="label">Headline</label>
                            <input name="pages[about][headline]" value="{{ $pages['about']['headline'] ?? '' }}" class="input" placeholder="Jewelry that tells your story">
                        </div>
                    </div>
                    <div>
                        <label class="label">Opening line <span class="text-ink-700/40">(one or two sentences under the headline)</span></label>
                        <textarea name="pages[about][lede]" rows="2" class="input">{{ $pages['about']['lede'] ?? '' }}</textarea>
                    </div>
                    <x-media-field name="about_hero_image" :value="theme_asset($pages['about']['hero_image'] ?? null) ?: ''" folder="branding"
                                   label="Header photo (optional)"
                                   help="A wide lifestyle shot. Left empty, the header is a clean cream band." />
                @endif
                <div>
                    <label class="label">Content (HTML)</label>
                    <textarea name="pages[{{ $key }}][body]" rows="8" class="input font-mono text-xs">{{ $pages[$key]['body'] ?? '' }}</textarea>
                    @if($key === 'about')
                        <p class="text-xs text-ink-700/50 mt-1">
                            Use <code>&lt;h2&gt;</code> for the section headings and <code>&lt;p&gt;</code> for paragraphs.
                            The promise strip below the story is your
                            <a href="{{ route('admin.appearance') }}#trust" class="text-gold-700 hover:underline">trust badges</a> —
                            edit them there and this page follows.
                        </p>
                    @endif
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
