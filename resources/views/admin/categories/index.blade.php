@extends('layouts.admin')
@section('title', 'Categories')
@section('heading', 'Categories')

@section('content')
<div class="grid lg:grid-cols-3 gap-6">
    <div class="card p-6 h-fit">
        <h2 class="font-semibold mb-4">Add category</h2>
        @if($errors->any())<div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mb-3">{{ $errors->first() }}</div>@endif
        <form action="{{ route('admin.categories.store') }}" method="POST" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <div><label class="label">Name *</label><input name="name" class="input" required></div>
            <div>
                <label class="label">Parent</label>
                <select name="parent_id" class="input"><option value="">— Top level —</option>
                    @foreach($parents as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                </select>
            </div>
            <div><label class="label">Description</label><textarea name="description" rows="2" class="input"></textarea></div>
            <div>
                <label class="label">Product page template (override)</label>
                <select name="product_template" class="input">
                    <option value="">Use store default</option>
                    @foreach(config('theme.product_templates') as $key => $tpl)
                        <option value="{{ $key }}">{{ $tpl['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label">Position</label><input name="position" type="number" value="0" class="input"></div>
                <label class="flex items-center gap-2 text-sm self-end pb-2"><input type="checkbox" name="is_active" value="1" checked> Active</label>
            </div>
            <div><label class="label">Image</label><input type="file" name="image" accept="image/*" class="input text-sm"></div>
            <button class="btn-primary w-full">Create</button>
        </form>
    </div>

    <div class="lg:col-span-2 card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Parent</th><th class="px-4 py-3">Products</th><th class="px-4 py-3">Active</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($categories as $cat)
                    <tr x-data="{ edit: false }" class="{{ $cat->parent_id ? 'bg-ink-50/40' : '' }}">
                        <td class="px-4 py-3">
                            <span x-show="!edit" class="{{ $cat->parent_id ? 'pl-6 text-ink-700/90' : 'font-medium' }}">
                                @if($cat->parent_id)<span class="text-ink-300 mr-1">└</span>@endif{{ $cat->name }}
                                @if($cat->product_template)<span class="badge bg-gold-100 text-gold-700 ml-1">{{ config('theme.product_templates.'.$cat->product_template.'.name', $cat->product_template) }}</span>@endif
                            </span>
                            <form x-show="edit" x-cloak action="{{ route('admin.categories.update', $cat) }}" method="POST" class="flex flex-wrap gap-1 items-center">
                                @csrf @method('PUT')
                                <input name="name" value="{{ $cat->name }}" class="input py-1 w-40">
                                <select name="product_template" class="input py-1 w-44">
                                    <option value="">Default template</option>
                                    @foreach(config('theme.product_templates') as $key => $tpl)
                                        <option value="{{ $key }}" @selected($cat->product_template==$key)>{{ $tpl['name'] }}</option>
                                    @endforeach
                                </select>
                                <input type="hidden" name="is_active" value="{{ $cat->is_active ? 1 : 0 }}">
                                <button class="btn-primary py-1 px-2 text-xs">Save</button>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-ink-700/70">{{ $cat->parent->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $cat->products()->count() }}</td>
                        <td class="px-4 py-3">{!! $cat->is_active ? '<span class="badge bg-green-100 text-green-700">Yes</span>' : '<span class="badge bg-ink-100 text-ink-700">No</span>' !!}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button @click="edit=!edit" class="text-gold-700 hover:underline">Edit</button>
                            <form action="{{ route('admin.categories.destroy', $cat) }}" method="POST" class="inline" onsubmit="return confirm('Delete category?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline ml-2">Delete</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-ink-700/50">No categories yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
