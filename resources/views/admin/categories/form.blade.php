@extends('layouts.admin')
@section('title', $category->exists ? 'Edit category' : 'Add category')
@section('heading', $category->exists ? 'Edit category · '.$category->name : 'Add category')

@section('content')
@if($errors->any())<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm">{{ $errors->first() }}</div>@endif

<form method="POST" action="{{ $category->exists ? route('admin.categories.update', $category) : route('admin.categories.store') }}" enctype="multipart/form-data" class="max-w-3xl">
    @csrf
    @if($category->exists) @method('PUT') @endif

    <div class="grid lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 card p-5 space-y-4">
            <div><label class="label">Name *</label><input name="name" value="{{ old('name', $category->name) }}" class="input" required></div>
            <div>
                <label class="label">Parent category</label>
                <select name="parent_id" class="input">
                    <option value="">— None (top level) —</option>
                    @foreach($parents as $p)
                        <option value="{{ $p->id }}" @selected(old('parent_id', $category->parent_id) == $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-ink-700/50 mt-1">Choose a parent to nest this under another category.</p>
            </div>
            <div><label class="label">Description</label><textarea name="description" rows="3" class="input">{{ old('description', $category->description) }}</textarea></div>

            <div class="border-t border-ink-100 pt-4">
                @php $catImg = $category->image ? (\Illuminate\Support\Str::startsWith($category->image,['http','/']) ? $category->image : \Illuminate\Support\Facades\Storage::disk('public')->url($category->image)) : ''; @endphp
                <x-media-field name="image" :value="$catImg" folder="categories"
                    label="Category image"
                    help="Shown on category tiles / Discover. Auto-optimized to WebP." />
            </div>

            <div class="border-t border-ink-100 pt-4 grid sm:grid-cols-2 gap-3">
                <div><label class="label">SEO title</label><input name="meta_title" value="{{ old('meta_title', $category->meta_title) }}" class="input"></div>
                <div><label class="label">SEO description</label><input name="meta_description" value="{{ old('meta_description', $category->meta_description) }}" class="input"></div>
            </div>
        </div>

        <div class="card p-5 h-fit space-y-4">
            <div>
                <label class="label">Product page template</label>
                <select name="product_template" class="input">
                    <option value="">Use store default</option>
                    @foreach(config('theme.product_templates') as $key => $tpl)
                        <option value="{{ $key }}" @selected(old('product_template', $category->product_template) == $key)>{{ $tpl['name'] }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-ink-700/50 mt-1">Products in this category use this layout.</p>
            </div>
            <div>
                <label class="label">Sort position</label>
                <input name="position" type="number" min="0" value="{{ old('position', $category->position ?? 0) }}" class="input">
                <p class="text-xs text-ink-700/50 mt-1">Lower numbers appear first among siblings. (You can also use the ▲▼ arrows on the list.)</p>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->is_active ?? true))> Active (visible on the storefront)</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_preorder" value="1" @checked(old('is_preorder', $category->is_preorder ?? false))> Pre-order category</label>

            <button class="btn-primary w-full">{{ $category->exists ? 'Save category' : 'Create category' }}</button>
            <a href="{{ route('admin.categories.index') }}" class="block text-center text-sm text-ink-700/60 hover:underline">Cancel</a>
        </div>
    </div>
</form>
@endsection
