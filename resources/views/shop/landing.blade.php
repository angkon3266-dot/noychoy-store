@extends('layouts.shop', ['hideChrome' => ! $page->show_header, 'hideFooter' => ! $page->show_footer])

@section('title', $page->meta_title ?: $page->title)

{{-- The OG tags this section used to emit now come from partials.seo-head,
     fed by LandingController: they were duplicating the layout's own og:*
     tags, and a page with two og:description values is a page whose preview
     is decided by whichever the scraper happens to read first. --}}

@section('content')
    @if(! $page->is_published)
        <div class="bg-amber-50 border-b border-amber-200 text-amber-800 text-sm px-4 py-2 text-center">
            Draft preview — this page is not visible to customers yet.
        </div>
    @endif

    @forelse($blocks as $block)
        <x-home-block :block="$block" />
    @empty
        <section class="mx-auto max-w-3xl px-4 py-24 text-center text-ink-700/60">
            <h1 class="font-display text-3xl text-ink-900 mb-3">{{ $page->title }}</h1>
            <p>This landing page has no sections yet.</p>
        </section>
    @endforelse
@endsection
