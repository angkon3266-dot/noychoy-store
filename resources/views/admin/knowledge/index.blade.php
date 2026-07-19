@extends('layouts.admin')
@section('title', 'Knowledge')
@section('heading', 'AI Knowledge Base')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <p class="text-sm text-ink-700/70 max-w-2xl">
        The business's single source of truth for AI tools ({{ $fileCount }} files).
        Product files are generated automatically when you save a product — everything
        else is yours to edit. Search for <code class="text-xs bg-ink-100 rounded px-1">[CONFIRM:</code>
        to find facts still waiting for your answer.
    </p>
    <form action="{{ route('admin.knowledge.sync') }}" method="POST">
        @csrf
        <button class="btn-outline" title="Regenerate every product's data block ({{ $productCount }} products); your written sections are preserved">↻ Sync products now</button>
    </form>
</div>

<div class="grid md:grid-cols-[260px_1fr] gap-4 items-start">
    {{-- File tree --}}
    <div class="card p-3 text-sm max-h-[75vh] overflow-y-auto">
        @foreach($rootFiles as $f)
            <a href="{{ route('admin.knowledge.index', ['file' => $f]) }}"
               class="block px-2 py-1 rounded {{ $selected === $f ? 'bg-gold-100 text-gold-800 font-medium' : 'hover:bg-ink-50' }}">📄 {{ $f }}</a>
        @endforeach
        @foreach($tree as $folder => $files)
            <details class="mt-1" {{ $selected && str_starts_with($selected, $folder.'/') ? 'open' : '' }}>
                <summary class="px-2 py-1 cursor-pointer font-medium text-ink-700">📁 {{ $folder }} <span class="text-xs text-ink-700/40">({{ $files->count() }})</span></summary>
                @foreach($files as $f)
                    <a href="{{ route('admin.knowledge.index', ['file' => $f]) }}"
                       class="block pl-6 pr-2 py-1 rounded truncate {{ $selected === $f ? 'bg-gold-100 text-gold-800 font-medium' : 'hover:bg-ink-50' }}">{{ basename($f) }}</a>
                @endforeach
            </details>
        @endforeach
    </div>

    {{-- Viewer / editor --}}
    <div class="card p-4">
        @if($selected)
            <form action="{{ route('admin.knowledge.save') }}" method="POST">
                @csrf
                <input type="hidden" name="file" value="{{ $selected }}">
                <div class="flex items-center justify-between gap-3 mb-2">
                    <h2 class="font-semibold text-sm truncate">{{ $selected }}</h2>
                    <button class="btn-primary py-1.5 text-sm">Save file</button>
                </div>
                @if(str_starts_with($selected, 'products/'))
                    <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2 mb-2">
                        The block between the two <code>---</code> lines is auto-generated from the
                        product database and will be overwritten on the next sync — edit only below it.
                    </p>
                @endif
                <textarea name="content" rows="28" spellcheck="false"
                          class="input font-mono text-xs leading-relaxed whitespace-pre">{{ $content }}</textarea>
            </form>
        @else
            <div class="text-center text-ink-700/50 py-24">
                <p class="text-4xl mb-3">📚</p>
                <p class="text-sm">Pick a file on the left to read or edit it.</p>
                <p class="text-xs mt-2">Start with <span class="font-medium">README.md</span> — it explains how the whole system works.</p>
            </div>
        @endif
    </div>
</div>
@endsection
