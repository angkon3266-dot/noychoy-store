@extends('layouts.admin')
@section('title', 'Try-on & stacker cut-outs')
@section('heading', 'Try-on & stacker cut-outs')

@section('flash')
    @if($errors->any())
        <div class="m-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">
            @foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach
        </div>
    @endif
@endsection

@section('content')
<div class="mb-5 max-w-3xl text-sm text-ink-700/70 space-y-2">
    <p>
        The <a href="{{ route('try-on') }}" target="_blank" rel="noopener" class="text-gold-700 underline">earring try-on</a> and the
        <a href="{{ route('stacker') }}" target="_blank" rel="noopener" class="text-gold-700 underline">bangle &amp; ring stacker</a>
        work for every piece already: without a cut-out, an earring shows its photo inside a drop shape and a bangle or ring is drawn in the colour of its photo.
    </p>
    <p>
        A <strong>cut-out</strong> makes it look real: a PNG of just the piece on a <strong>transparent background</strong>, front-on, nothing else in it.
        Earrings: one earring, hanging straight, the hook at the top. Bangles: the bangle seen from the front. Rings: the ring seen from the front.
        Any “remove background” tool makes one from a product photo in a few seconds.
    </p>
</div>

@foreach($groups as $kind => $products)
    <h2 class="mt-8 mb-3 font-medium text-ink-900">{{ ucfirst($kind) }}s <span class="text-ink-700/50 font-normal">· {{ $products->filter(fn ($p) => $p->cutout_image)->count() }} of {{ $products->count() }} have a cut-out</span></h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
        @foreach($products as $p)
            <div class="card p-3 flex gap-3">
                <div class="w-16 h-16 shrink-0 rounded bg-[conic-gradient(#eee_25%,#fff_0_50%,#eee_0_75%,#fff_0)] bg-[length:12px_12px] overflow-hidden grid place-items-center">
                    @if($p->cutout_image)
                        <img src="{{ \App\Support\Storefront\StylePieces::drawImage($p) }}" alt="" class="max-w-full max-h-full object-contain">
                    @else
                        <img src="{{ image_variant($p->thumbnail) ?: $p->thumbnail }}" alt="" class="w-full h-full object-cover opacity-60">
                    @endif
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium truncate" title="{{ $p->name }}">{{ $p->name }}</p>
                    <form method="POST" action="{{ route('admin.looks.cutouts.save', $p) }}" enctype="multipart/form-data" data-no-ajax class="mt-1">
                        @csrf
                        <label class="text-xs text-gold-700 underline cursor-pointer">
                            {{ $p->cutout_image ? 'Replace cut-out' : 'Upload cut-out' }}
                            <input type="file" name="cutout" accept="image/png,image/webp" class="sr-only" onchange="this.form.submit()">
                        </label>
                    </form>
                    @if($p->cutout_image)
                        <form method="POST" action="{{ route('admin.looks.cutouts.remove', $p) }}" data-no-ajax>
                            @csrf @method('DELETE')
                            <button class="text-xs text-red-600 hover:underline">Remove</button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endforeach
@endsection
