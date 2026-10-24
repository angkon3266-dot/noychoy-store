{{-- The hero: always first on the page. --}}
<x-admin.home-panel title="Hero (top of the page)" hint="Headline, buttons and the photo / video slideshow" :home="$home" fixed>
    @include('admin.appearance.home._texts', ['fields' => [
        'hero_eyebrow' => 'Small text above the headline',
        'hero_highlight' => 'Highlighted words (shown in italics and colour)',
        'hero_heading' => 'Headline',
        'hero_subtitle' => 'Line under the headline',
        'hero_cta_text' => 'Main button text',
        'hero_cta_link' => 'Main button link',
        'hero_finder_label' => 'Gift button text (scrolls to the gift finder)',
        'hero_secondary_text' => 'Small link under the buttons (leave empty to hide)',
        'hero_secondary_link' => 'Small link goes to',
    ]])

    {{-- The trust line under the buttons --}}
    @php $trust = collect($home['hero_trust'] ?? [])->values(); @endphp
    <div x-data="{ rows: @js($trust->all()) }">
        <input type="hidden" name="hero_trust_present" value="1">
        <label class="label">Trust points under the buttons (up to 3)</label>
        <template x-for="(r, i) in rows" :key="i">
            <div class="flex gap-2 mb-2">
                <input :name="`hero_trust[${i}]`" x-model="rows[i]" class="input flex-1" maxlength="60" placeholder="Cash on delivery">
                <button type="button" @click="rows.splice(i, 1)" class="text-red-500 px-2 text-xl leading-none" title="Remove">&times;</button>
            </div>
        </template>
        <button type="button" x-show="rows.length < 3" @click="rows.push('')" class="btn-outline text-xs py-1">+ Add a point</button>
    </div>

    {{-- Slides --}}
    <div class="border-t border-ink-100 pt-4">
        <h3 class="text-sm font-semibold text-ink-800 mb-1">Slideshow</h3>
        @php
            // Kept unfiltered on purpose: the edit/remove index below has to match
            // the raw array the controller saves against, and a video slide has no
            // "image" key to filter on.
            $slides = collect($home['hero_slides'] ?? []);
        @endphp
        @if($slides->isNotEmpty())
            {{-- Drag a row by its ⠿ grip or nudge it with ▲/▼ — heroSlideOrder in
                 app.js renumbers every row's hidden position, and the order is
                 saved with the rest of the form. --}}
            <p class="text-xs text-ink-700/50 mb-2">Drag ⠿ or use the arrows to change the order. Click <strong>Edit slide</strong> to change a picture, its timing or a video's sound button.</p>
            <div class="space-y-3 mb-4" x-data="heroSlideOrder">
                @foreach($slides as $i => $s)
                    @php
                        $isVideo = filled($s['video'] ?? null);
                        $simg = $isVideo ? null : (\Illuminate\Support\Str::startsWith($s['image'], ['http', '/']) ? $s['image'] : \Illuminate\Support\Facades\Storage::disk('public')->url($s['image']));
                    @endphp
                    <div class="rounded-lg border border-ink-100 bg-white" data-slide-row x-data="{ edit: false }">
                        <div class="flex items-center gap-3 p-2">
                            <input type="hidden" name="hero_slides[{{ $i }}][position]" value="{{ $loop->index }}" data-slide-pos>
                            <span data-slide-grip class="shrink-0 cursor-grab active:cursor-grabbing touch-none select-none px-1 text-lg leading-none text-ink-700/40 hover:text-ink-900" title="Drag to reorder">⠿</span>
                            <div class="flex flex-col shrink-0">
                                <button type="button" data-slide-up @click="move($el.closest('[data-slide-row]'), -1)" class="w-7 h-6 rounded text-ink-700/60 hover:bg-ink-100 hover:text-ink-900 leading-none disabled:opacity-30" @disabled($loop->first) title="Move up">▲</button>
                                <button type="button" data-slide-down @click="move($el.closest('[data-slide-row]'), 1)" class="w-7 h-6 rounded text-ink-700/60 hover:bg-ink-100 hover:text-ink-900 leading-none disabled:opacity-30" @disabled($loop->last) title="Move down">▼</button>
                            </div>
                            @if($isVideo)
                                @php $vmeta = video_meta($s['video']); @endphp
                                <div class="w-24 h-14 rounded bg-ink-900 shrink-0 grid place-items-center relative overflow-hidden">
                                    @if($vmeta && $vmeta['thumb'])
                                        <img src="{{ $vmeta['thumb'] }}" class="absolute inset-0 w-full h-full object-cover opacity-70" alt="">
                                    @endif
                                    <span class="relative text-white text-lg">▶</span>
                                </div>
                            @else
                                <img src="{{ $simg }}" class="w-24 h-14 object-cover rounded shrink-0" alt="" draggable="false">
                            @endif
                            <input name="hero_slides[{{ $i }}][link]" value="{{ $s['link'] ?? '' }}" class="input flex-1" placeholder="Link when clicked (optional)">
                            <button type="button" @click="edit = !edit" class="shrink-0 text-xs font-medium text-gold-700 hover:underline whitespace-nowrap" x-text="edit ? 'Close' : 'Edit slide'"></button>
                            <label class="flex items-center gap-1 text-xs text-red-600 shrink-0"><input type="checkbox" name="hero_slides[{{ $i }}][remove]" value="1"> Remove</label>
                        </div>
                        <div x-show="edit" x-cloak class="border-t border-ink-100 p-3 grid sm:grid-cols-[auto_1fr] gap-4 bg-ink-50/40">
                            @if($isVideo)
                                <div class="sm:col-span-2 grid sm:grid-cols-2 gap-3">
                                    <div class="sm:col-span-2">
                                        <label class="label">Video link (YouTube / Vimeo) or file</label>
                                        <input name="hero_slides[{{ $i }}][video]" value="{{ $s['video'] }}" class="input" placeholder="https://www.youtube.com/watch?v=…">
                                        <p class="text-xs text-ink-700/50 mt-1">Paste a different link to swap the video. It plays by itself, silently and on a loop.</p>
                                    </div>
                                    <div>
                                        <label class="label">Seconds on screen</label>
                                        <input type="number" min="2" max="120" step="0.5" name="hero_slides[{{ $i }}][seconds]" value="{{ $s['seconds'] ?? '' }}" class="input" placeholder="15">
                                    </div>
                                    <div class="self-end pb-2">
                                        <input type="hidden" name="hero_slides[{{ $i }}][sound_present]" value="1">
                                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="hero_slides[{{ $i }}][sound]" value="1" @checked($s['sound'] ?? true)> Show a sound button on this video</label>
                                    </div>
                                </div>
                            @else
                                <x-media-field :name="'hero_slide_image_'.$i" :value="$simg" folder="hero" />
                                <div class="grid sm:grid-cols-2 gap-3 content-start">
                                    <div class="sm:col-span-2">
                                        <label class="label">Describe the picture (for search engines and screen readers)</label>
                                        <input name="hero_slides[{{ $i }}][alt]" value="{{ $s['alt'] ?? '' }}" class="input" maxlength="140" placeholder="Model wearing the pink drop necklace">
                                    </div>
                                    <div>
                                        <label class="label">Seconds on screen</label>
                                        <input type="number" min="2" max="120" step="0.5" name="hero_slides[{{ $i }}][seconds]" value="{{ $s['seconds'] ?? '' }}" class="input" placeholder="{{ $home['hero_slide_seconds'] ?? '6.5' }}">
                                    </div>
                                    <p class="sm:col-span-2 text-xs text-ink-700/50">Upload or pick a new picture to replace this one — the link and position stay.</p>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-xs text-ink-700/50 mb-3">No slides yet — the hero shows your fallback picture and featured products until you add some.</p>
        @endif

        <div class="grid sm:grid-cols-2 gap-3 mb-4">
            <div>
                <label class="label">Seconds each picture stays on screen</label>
                <input type="number" min="2" max="60" step="0.5" name="home[hero_slide_seconds]" value="{{ $home['hero_slide_seconds'] ?? '' }}" class="input" placeholder="6.5">
            </div>
        </div>

        <label class="label">Add pictures</label>
        @php $existingSlides = $slides->count(); @endphp
        <div x-data="{ rows: 1, max: Math.max(1, 10 - {{ $existingSlides }}), picks: [] }">
            <div class="flex flex-wrap gap-2 mb-2" x-show="picks.length" x-cloak>
                <template x-for="(u, i) in picks" :key="i">
                    <div class="relative">
                        <img :src="u.startsWith('http') || u.startsWith('/') ? u : '/storage/'+u" class="w-20 h-12 object-cover rounded border border-ink-100">
                        <input type="hidden" name="hero_slide_urls[]" :value="u">
                        <button type="button" @click="picks.splice(i,1)" class="absolute -top-1.5 -right-1.5 bg-red-600 text-white rounded-full w-4 h-4 text-[10px] leading-none">&times;</button>
                    </div>
                </template>
            </div>
            <button type="button" @click="$store.mediaLib.openWith(sel => (Array.isArray(sel) ? sel : [sel]).forEach(u => picks.push(u)), 'hero', { multi: true })"
                    class="btn-outline text-sm mb-3">🖼 Add from media library</button>
            <p class="text-xs text-ink-700/50 mb-1">…or upload from your device:</p>
            <template x-for="r in rows" :key="r">
                <div class="flex items-center gap-2 mb-2">
                    <input type="file" name="hero_slide_images[]" accept="image/*" multiple class="input text-sm flex-1">
                    <button type="button" x-show="rows > 1" @click="rows--" class="shrink-0 text-red-600 text-sm px-2 py-1 hover:bg-red-50 rounded" title="Remove this row">✕</button>
                </div>
            </template>
            <button type="button" @click="if (rows < max) rows++" x-show="rows < max" class="mt-1 inline-flex items-center gap-1 text-sm text-gold-700 font-medium hover:text-gold-800">
                <span class="text-lg leading-none">＋</span> Add another picture <span class="text-ink-700/40" x-text="'(' + rows + '/' + max + ')'"></span>
            </button>
            <p class="text-xs text-ink-700/50 mt-2">Up to 10 slides. Square or portrait pictures work best on phones; the slide crops to fill its frame.</p>
        </div>

        <label class="label mt-4">Add a video</label>
        <p class="text-xs text-ink-700/50 mb-2">A YouTube or Vimeo link, or your own clip. It starts playing by itself — silently, because browsers only allow that — and loops. Shoppers get a sound button on it (you can switch that off per video under <strong>Edit slide</strong>). A video stays up for 15 seconds unless you set another time.</p>
        <div x-data="{ rows: 1 }">
            <template x-for="r in rows" :key="r">
                <div class="flex items-center gap-2 mb-2">
                    <input name="hero_slide_video_urls[]" class="input text-sm flex-1" placeholder="https://www.youtube.com/watch?v=…">
                    <span class="text-xs text-ink-700/40">or</span>
                    <input type="file" name="hero_slide_videos[]" accept="video/mp4,video/webm,video/quicktime" class="input text-sm flex-1">
                    <button type="button" x-show="rows > 1" @click="rows--" class="shrink-0 text-red-600 text-sm px-2 py-1 hover:bg-red-50 rounded" title="Remove this row">✕</button>
                </div>
            </template>
            <button type="button" @click="rows++" class="mt-1 inline-flex items-center gap-1 text-sm text-gold-700 font-medium hover:text-gold-800">
                <span class="text-lg leading-none">＋</span> Add another video
            </button>
            <p class="text-xs text-ink-700/50 mt-2">Video files up to 50MB.</p>
        </div>
    </div>

    <div class="border-t border-ink-100 pt-4">
        <x-media-field name="hero_image" :value="theme_asset($home['hero_image']) ?: ''" folder="branding"
            label="Fallback picture (only used when there are no slides)"
            help="With no slides, the hero shows this and your featured products." />
    </div>
</x-admin.home-panel>
