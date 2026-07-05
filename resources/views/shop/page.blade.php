@extends('layouts.shop')
@section('title', $title)

@section('content')
<div class="mx-auto max-w-3xl px-4 py-12">
    <h1 class="font-display text-3xl font-semibold mb-6">{{ $title }}</h1>
    <div class="prose prose-sm sm:prose max-w-none text-ink-700/85 prose-headings:font-display prose-headings:text-ink-900 prose-a:text-gold-700">
        {!! $body !!}
    </div>
</div>
@endsection
