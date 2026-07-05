@extends('layouts.admin')
@section('title', 'Content Templates')
@section('heading', 'Product Story Templates')

@section('content')
<div class="grid lg:grid-cols-3 gap-6">
    {{-- Builder (create / edit) --}}
    <div class="lg:col-span-2 card p-6"
         x-data="sectionBuilder(@js($editing->sections ?? []), { uploadUrl: '{{ route('admin.products.section-image') }}', csrf: '{{ csrf_token() }}' })">
        <h2 class="font-semibold mb-3">{{ $editing ? 'Edit template' : 'New template' }}</h2>

        <form action="{{ $editing ? route('admin.content-templates.update', $editing) : route('admin.content-templates.store') }}" method="POST" class="space-y-4">
            @csrf
            @if($editing) @method('PUT') @endif

            <div>
                <label class="label">Template name</label>
                <input name="name" value="{{ old('name', $editing->name ?? '') }}" class="input" placeholder="e.g. Editorial — Earrings" required>
            </div>

            <template x-for="(s, i) in sections" :key="i">
                <div class="rounded-lg border border-ink-100 p-3">
                    <div class="flex items-center justify-between text-xs text-ink-700/50 mb-2">
                        <span>Section <span x-text="i + 1"></span></span>
                        <div class="flex gap-2">
                            <button type="button" @click="move(i, -1)" class="hover:text-gold-700">↑</button>
                            <button type="button" @click="move(i, 1)" class="hover:text-gold-700">↓</button>
                            <button type="button" @click="remove(i)" class="text-red-600 hover:underline">Remove</button>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <div class="w-28 shrink-0">
                            <div class="aspect-square rounded bg-ink-100 overflow-hidden mb-1">
                                <template x-if="s.image"><img :src="s.image" class="w-full h-full object-cover" alt=""></template>
                            </div>
                            <label class="btn-outline text-xs py-1 w-full text-center cursor-pointer block">Upload
                                <input type="file" accept="image/*" class="hidden" @change="upload(i, $event)">
                            </label>
                        </div>
                        <div class="flex-1 space-y-2">
                            <input x-model="s.heading" placeholder="Heading" class="input py-2">
                            <textarea x-model="s.body" rows="3" placeholder="Description" class="input"></textarea>
                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                <label class="flex items-center gap-1"><input type="radio" value="right" x-model="s.layout"> Image right</label>
                                <label class="flex items-center gap-1"><input type="radio" value="left" x-model="s.layout"> Image left</label>
                                <input x-model="s.image" placeholder="or paste image URL" class="input py-1 text-xs flex-1 min-w-40">
                            </div>
                        </div>
                    </div>
                </div>
            </template>

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
