@extends('layouts.admin')
@section('title', 'Content Templates')
@section('heading', 'Product Story Templates')

@section('content')
<div class="grid lg:grid-cols-3 gap-6">
    {{-- Builder (create / edit) --}}
    <div class="lg:col-span-2 card p-6"
         x-data="sectionBuilder(@js($editing->sections ?? []), { uploadUrl: '{{ route('admin.products.section-image') }}', videoUploadUrl: '{{ route('admin.products.section-video') }}', csrf: '{{ csrf_token() }}' })">
        <h2 class="font-semibold mb-3">{{ $editing ? 'Edit template' : 'New template' }}</h2>

        <form action="{{ $editing ? route('admin.content-templates.update', $editing) : route('admin.content-templates.store') }}" method="POST" class="space-y-4">
            @csrf
            @if($editing) @method('PUT') @endif

            <div>
                <label class="label">Template name</label>
                <input name="name" value="{{ old('name', $editing->name ?? '') }}" class="input" placeholder="e.g. Editorial — Earrings" required>
            </div>

            @include('admin.partials.story-section-fields')

            <button type="button" @click="add()" class="btn-outline text-sm">+ Add section</button>

            <input type="hidden" name="sections_json" :value="json">
            <div class="flex gap-2 pt-2 border-t border-ink-100">
                <button class="btn-primary">{{ $editing ? 'Update template' : 'Create template' }}</button>
                @if($editing)<a href="{{ route('admin.content-templates.index') }}" class="btn-outline">Cancel</a>@endif
            </div>
        </form>
    </div>

    {{-- Library --}}
    <div class="card p-5 h-fit">
        <h2 class="font-semibold mb-3">Saved templates</h2>
        <div class="space-y-2">
            @forelse($templates as $t)
                <div class="flex items-center justify-between gap-2 rounded-lg border border-ink-100 px-3 py-2 text-sm {{ ($editing && $editing->id === $t->id) ? 'border-gold-300 bg-gold-50/40' : '' }}">
                    <div>
                        <div class="font-medium">{{ $t->name }}</div>
                        <div class="text-xs text-ink-700/50">{{ count($t->sections ?? []) }} section(s)</div>
                    </div>
                    <div class="flex gap-2">
                        <a href="{{ route('admin.content-templates.index', ['edit' => $t->id]) }}" class="text-gold-700 hover:underline text-xs">Edit</a>
                        <form action="{{ route('admin.content-templates.destroy', $t) }}" method="POST" onsubmit="return confirm('Delete this template?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline text-xs">Delete</button></form>
                    </div>
                </div>
            @empty
                <p class="text-sm text-ink-700/50">No templates yet. Build one on the left, or use “Save as template” on a product.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
