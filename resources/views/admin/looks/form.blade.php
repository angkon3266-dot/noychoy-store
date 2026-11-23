@extends('layouts.admin')
@section('title', $look->exists ? 'Edit look' : 'New look')
@section('heading', $look->exists ? 'Edit look' : 'New look')

@section('flash')
    @if($errors->any())
        <div class="m-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">
            @foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach
        </div>
    @endif
@endsection

@section('content')
<form method="POST" enctype="multipart/form-data" data-no-ajax
      action="{{ $look->exists ? route('admin.looks.update', $look) : route('admin.looks.store') }}"
      x-data="lookEditor(@js($look->cleanHotspots()), @js($products), @js($look->image_url))"
      class="grid lg:grid-cols-12 gap-6">
    @csrf
    @if($look->exists) @method('PUT') @endif
    <input type="hidden" name="hotspots" :value="JSON.stringify(spots.map(s => ({x: s.x, y: s.y, product_id: Number(s.product_id)})).filter(s => s.product_id))">

    {{-- The photo: click to pin --}}
    <div class="lg:col-span-7">
        <div class="card p-3">
            <template x-if="image">
                <div class="relative select-none cursor-crosshair" x-ref="photo" @click="add($event)">
                    <img :src="image" alt="" class="block w-full h-auto rounded-md pointer-events-none">
                    <template x-for="(s, i) in spots" :key="i">
                        <button type="button" @click.stop="active = i"
                                class="absolute -translate-x-1/2 -translate-y-1/2 grid place-items-center w-8 h-8 rounded-full text-xs font-bold ring-2 ring-white shadow-lg"
                                :class="active === i ? 'bg-gold-400 text-ink-900' : (s.product_id ? 'bg-ink-900 text-white' : 'bg-red-600 text-white')"
                                :style="`left:${s.x}%;top:${s.y}%`" x-text="i + 1"></button>
                    </template>
                </div>
            </template>
            <template x-if="!image">
                <div class="aspect-[4/5] grid place-items-center rounded-md bg-ink-50 text-sm text-ink-700/60 text-center p-8">Choose a photo on the right — then click on each piece in it to pin it.</div>
            </template>
            <p class="mt-2 text-xs text-ink-700/60" x-show="image">Click on a piece in the photo to drop a pin, then choose which product it is. Red pins have no product yet and are not saved.</p>
        </div>
    </div>

    {{-- Details + pins --}}
    <div class="lg:col-span-5 space-y-4">
        <div class="card p-4 space-y-3">
            <label class="block"><span class="label">Title</span>
                <input name="title" value="{{ old('title', $look->title) }}" class="input" maxlength="120" required placeholder="Emerald evening">
            </label>
            <label class="block"><span class="label">Caption (optional)</span>
                <textarea name="caption" rows="2" class="input" maxlength="300" placeholder="Statement drops, a slim bangle stack and a cocktail ring.">{{ old('caption', $look->caption) }}</textarea>
            </label>
            <label class="block"><span class="label">Photo {{ $look->exists ? '(choose a new one to replace it)' : '' }}</span>
                <input type="file" name="image" accept="image/*" class="input" @change="preview($event)">
            </label>
            <div class="flex items-center gap-6">
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $look->is_active))> Show on the site</label>
                <label class="inline-flex items-center gap-2 text-sm">Order <input type="number" name="position" value="{{ old('position', $look->position ?? 0) }}" min="0" class="input w-20"></label>
            </div>
        </div>

        <div class="card p-4">
            <p class="font-medium text-sm mb-2">Pinned pieces</p>
            <p class="text-xs text-ink-700/60 mb-3" x-show="!spots.length">No pins yet.</p>
            <template x-for="(s, i) in spots" :key="i">
                <div class="flex items-center gap-2 py-2 border-t border-ink-100" :class="active === i ? 'bg-gold-50 -mx-2 px-2 rounded' : ''" @click="active = i">
                    <span class="grid place-items-center w-6 h-6 shrink-0 rounded-full bg-ink-900 text-white text-[11px] font-bold" x-text="i + 1"></span>
                    <template x-if="productOf(s)"><img :src="productOf(s).thumb" alt="" class="w-9 h-9 rounded object-cover shrink-0"></template>
                    <select x-model="s.product_id" class="input flex-1 min-w-0 text-sm" :id="`pin-${i}`">
                        <option value="">Which piece is this?</option>
                        <template x-for="p in products" :key="p.id"><option :value="p.id" x-text="p.name" :selected="Number(s.product_id) === p.id"></option></template>
                    </select>
                    <button type="button" @click.stop="spots.splice(i, 1); active = null" class="text-red-500 text-xl leading-none px-1" aria-label="Remove pin">&times;</button>
                </div>
            </template>
        </div>

        <div class="flex gap-2">
            <button class="btn-primary">Save look</button>
            <a href="{{ route('admin.looks.index') }}" class="btn-outline">Back</a>
        </div>
    </div>
</form>

<script>
    function lookEditor(spots, products, image) {
        return {
            spots: spots.map(s => ({ ...s, product_id: String(s.product_id) })),
            products,
            image,
            active: null,
            add(e) {
                const r = this.$refs.photo.getBoundingClientRect();
                const x = Math.round(((e.clientX - r.left) / r.width) * 10000) / 100;
                const y = Math.round(((e.clientY - r.top) / r.height) * 10000) / 100;
                this.spots.push({ x, y, product_id: '' });
                this.active = this.spots.length - 1;
                this.$nextTick(() => document.getElementById(`pin-${this.active}`)?.focus());
            },
            preview(e) {
                const f = e.target.files?.[0];
                if (f) this.image = URL.createObjectURL(f);
            },
            productOf(s) { return this.products.find(p => p.id === Number(s.product_id)); },
        };
    }
</script>
@endsection
