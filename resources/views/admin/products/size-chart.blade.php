@extends('layouts.admin')
@section('title', 'Size chart')
@section('heading', 'Ring & bangle size chart')

@section('flash')
    @if($errors->any())<div class="m-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">{{ $errors->first() }}</div>@endif
@endsection

@section('content')
@php
    $kinds = [
        'ring' => ['title' => 'Rings', 'what' => 'round the finger', 'example' => 'e.g. 7', 'tryLabel' => 'A finger that measures', 'tol' => 0.5],
        'bangle' => ['title' => 'Bangles', 'what' => 'round the hand, over the knuckles', 'example' => 'e.g. 2.6', 'tryLabel' => 'A hand that measures', 'tol' => 1.0],
    ];
@endphp
<div class="max-w-4xl space-y-6">
    <div class="card p-5 text-sm text-ink-700/80 leading-relaxed">
        <p><strong class="text-ink-900">"Find my size" on ring and bangle pages answers from this chart.</strong>
            Write each size the way it appears on your products (7, 7.5, 2.6, 2.10) and how many millimetres it measures
            <em>round</em> — the number a paper tape shows wrapped round the finger. The inside width is worked out for you.</p>
        <p class="mt-2">Shoppers see it in the finder's "Size chart" tab, and their measurement picks the smallest size that fits.
            Add every size you sell: a size missing here is never suggested.</p>
    </div>

    <form method="POST" action="{{ route('admin.products.size-chart.update') }}" enctype="multipart/form-data" data-no-ajax class="space-y-6">
        @csrf
        @foreach($kinds as $kind => $k)
            @php $chart = $charts[$kind]; @endphp
            <div class="card p-5"
                 x-data="{
                    rows: @js(collect($chart['rows'])->map(fn ($r) => ['size' => $r['size'], 'around' => $r['around']])->values()),
                    standard: @js($chart['standard']),
                    tryMm: '',
                    across(a) { const v = parseFloat(a); return v > 0 ? (v / Math.PI).toFixed(1) : '—'; },
                    fits() {
                        const m = parseFloat(this.tryMm);
                        if (!(m > 0)) return null;
                        const sorted = this.rows.filter(r => String(r.size).trim() && parseFloat(r.around) > 0)
                            .slice().sort((a, b) => parseFloat(a.around) - parseFloat(b.around));
                        const hit = sorted.find(r => parseFloat(r.around) >= m - {{ $k['tol'] }});
                        return hit ? 'Size ' + hit.size : 'Bigger than your largest size';
                    },
                 }">
                <div class="flex flex-wrap items-baseline justify-between gap-3 mb-3">
                    <h2 class="font-semibold text-lg">{{ $k['title'] }}</h2>
                    <span class="text-xs {{ $chart['custom'] ? 'text-green-700' : 'text-ink-700/60' }}">
                        {{ $chart['custom'] ? 'Your own chart' : 'Standard chart — edit it to make it yours' }}
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-ink-700/60">
                            <tr>
                                <th class="py-2 pr-3 font-medium">Size</th>
                                <th class="py-2 pr-3 font-medium">Round {{ $k['what'] }} (mm)</th>
                                <th class="py-2 pr-3 font-medium">Inside width</th>
                                <th class="py-2"><span class="sr-only">Remove</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, i) in rows" :key="i">
                                <tr class="border-t border-ink-100">
                                    <td class="py-1.5 pr-3"><input :name="'{{ $kind }}[' + i + '][size]'" x-model="row.size" maxlength="12" class="input py-1.5 w-28" placeholder="{{ $k['example'] }}"></td>
                                    <td class="py-1.5 pr-3"><input :name="'{{ $kind }}[' + i + '][around]'" x-model="row.around" type="number" step="0.1" min="{{ $chart['limits'][0] }}" max="{{ $chart['limits'][1] }}" class="input py-1.5 w-32"></td>
                                    <td class="py-1.5 pr-3 tabular-nums text-ink-700/70"><span x-text="across(row.around)"></span> mm</td>
                                    <td class="py-1.5 text-right"><button type="button" @click="rows.splice(i, 1)" class="text-xs text-red-700 hover:underline">Remove</button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" @click="rows.push({ size: '', around: '' })" class="btn-outline text-sm">+ Add a size</button>
                    <button type="button" @click="if (confirm('Replace this chart with the standard sizes? Nothing is saved until you press Save.')) rows = standard.map(r => ({ ...r }))" class="btn-outline text-sm">Use the standard chart</button>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    @foreach([
                        'measure' => ['How-to-measure picture', 'Shown in the finder’s Measure tab instead of our photo — e.g. your own photo of a tape round the '.($kind === 'ring' ? 'finger' : 'knuckles').'.'],
                        'chart' => ['Your size chart picture', 'Shown at the top of the finder’s Size chart tab — e.g. your printed chart.'],
                    ] as $slot => [$slotTitle, $slotHelp])
                        @php $current = $chart['images'][$slot] ?? null; @endphp
                        <div class="rounded-lg border border-ink-100 p-3" x-data="{ preview: @js($current), removed: false }">
                            <p class="text-sm font-medium">{{ $slotTitle }}</p>
                            <p class="text-xs text-ink-700/60 mt-0.5 mb-2">{{ $slotHelp }}</p>
                            <template x-if="preview && !removed">
                                <img :src="preview" alt="" class="mb-2 max-h-40 rounded-md border border-ink-100 object-contain bg-ink-50">
                            </template>
                            <template x-if="!preview || removed">
                                <p class="mb-2 text-xs text-ink-700/50">{{ $slot === 'measure' ? 'None — the store’s own photo is used.' : 'None — only the table is shown.' }}</p>
                            </template>
                            <input type="file" name="{{ $kind }}_{{ $slot }}_image" accept="image/jpeg,image/png,image/webp" class="block w-full text-xs"
                                   @change="const f = $event.target.files[0]; if (f) { preview = URL.createObjectURL(f); removed = false; }">
                            @if($current)
                                <label class="mt-2 flex items-center gap-2 text-xs text-red-700">
                                    <input type="checkbox" name="remove_{{ $kind }}_{{ $slot }}_image" value="1" x-model="removed"> Remove this picture
                                </label>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 rounded-lg bg-ink-50 px-4 py-3 text-sm flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2">
                        <span class="text-ink-700/70">Try it — {{ strtolower($k['tryLabel']) }}</span>
                        <input x-model="tryMm" type="number" step="0.5" class="input py-1 w-24" placeholder="60">
                        <span class="text-ink-700/70">mm round</span>
                    </label>
                    <span class="font-semibold" x-show="fits()" x-text="'→ ' + fits()"></span>
                </div>
            </div>
        @endforeach

        <div class="flex items-center gap-3">
            <button class="btn-primary">Save size chart</button>
            <span class="text-xs text-ink-700/60">Clear every row of a chart and save to go back to the standard sizes.</span>
        </div>
    </form>
</div>
@endsection
