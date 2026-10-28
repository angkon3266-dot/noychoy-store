{{--
    The homepage builder (owner, 2026-10-24): every section of the homepage in
    the order it appears, each with an on/off switch and everything it shows.
    Built-in sections come from config('home.sections'); custom sections are
    the old "section builder" blocks, now placed among them.
--}}
@php
    $blocksById = collect($homeBlocks)->keyBy('id');
@endphp
<div id="home-builder" x-show="tab==='homepage'"
     x-data="homePreview({ previewUrl: @js(route('admin.appearance.preview')), homeUrl: @js(route('home')) })"
     class="xl:grid xl:grid-cols-[minmax(0,1fr)_minmax(420px,44%)] xl:gap-6 xl:items-start">
<div class="space-y-4 min-w-0" x-data="homeSections">
    <div class="card p-5 flex flex-wrap items-start justify-between gap-4">
        <div class="max-w-2xl">
            <h2 class="font-semibold">Homepage builder</h2>
            <p class="text-xs text-ink-700/60 mt-1">
                Your homepage, top to bottom. Click a section to edit its words, pictures and products; use the switch to show or hide it;
                drag <strong>⠿</strong> (or ▲ ▼) to change the order. Add your own sections at the bottom. Nothing changes on the shop until you press <strong>Save</strong>.
            </p>
        </div>
        <a href="{{ route('home') }}" target="_blank" class="btn-outline text-sm shrink-0">View homepage ↗</a>
    </div>

    {{-- Which design lays the page out. Couture and Editorial are built from
         the sections below and show in the live preview; the classic designs
         are older pages that read only part of the builder. --}}
    @php
        $reactDesigns = \App\Support\HomePage::REACT_TEMPLATES;
        $designBlurbs = [
            'couture' => 'Split hero with a story-style slideshow, rounded tiles and soft panels.',
            'editorial' => 'A magazine cover hero, giant outlined type, a category index, an editor’s spread and one pull-quote at a time.',
        ];
    @endphp
    <x-admin.home-panel title="Design" hint="How the homepage is laid out — the sections below fill it" :home="$home" fixed :open="true" focus="hero">
        <div x-data="{ sel: @js($theme['homepage_template']) }" class="space-y-4">
            <div class="grid sm:grid-cols-2 gap-3">
                @foreach($reactDesigns as $key)
                    @php $tpl = $homeTemplates[$key] ?? null; @endphp
                    @continue(! $tpl)
                    <label class="cursor-pointer rounded-xl border p-4 transition"
                           :class="sel === '{{ $key }}' ? 'border-gold-500 bg-gold-50 ring-1 ring-gold-400' : 'border-ink-100 hover:border-gold-300'">
                        <input type="radio" name="homepage_template" value="{{ $key }}" class="sr-only" x-model="sel" @checked($theme['homepage_template'] == $key)>
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-sm">{{ $tpl['name'] }}</span>
                            <span x-show="sel === '{{ $key }}'" class="rounded-full bg-gold-600 text-white text-[10px] px-2 py-0.5">Selected</span>
                        </div>
                        <p class="text-xs text-ink-700/60 mt-1.5">{{ $designBlurbs[$key] ?? '' }}</p>
                    </label>
                @endforeach
            </div>
            <details>
                <summary class="cursor-pointer text-xs font-medium text-ink-700/70">Classic designs (older pages — the builder shapes them only in part, and the live preview cannot show them)</summary>
                <div class="mt-3 grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                    @foreach($homeTemplates as $key => $tpl)
                        @continue(in_array($key, $reactDesigns, true))
                        <label class="cursor-pointer rounded-lg border p-3 transition"
                               :class="sel === '{{ $key }}' ? 'border-gold-500 bg-gold-50 ring-1 ring-gold-400' : 'border-ink-100 hover:border-gold-300'">
                            <input type="radio" name="homepage_template" value="{{ $key }}" class="sr-only" x-model="sel" @checked($theme['homepage_template'] == $key)>
                            <div class="font-medium text-sm">{{ $tpl['name'] }}</div>
                            <div class="text-[11px] text-ink-700/50 mt-0.5">Inspired by {{ $tpl['inspiration'] }}</div>
                            <a href="{{ route('home') }}?preview_home={{ $key }}" target="_blank" class="text-[11px] text-gold-700 hover:underline mt-1 inline-block">Preview ↗</a>
                        </label>
                    @endforeach
                </div>
            </details>
            <p class="text-xs text-ink-700/50">Pick a design and the preview on the right switches straight away; it goes live when you press <strong>Save</strong>.</p>
        </div>
    </x-admin.home-panel>

    {{-- Not sections: the page's own settings. --}}
    <x-admin.home-panel title="Search engines" hint="The homepage's title and description in Google" :home="$home" fixed>
        <div class="max-w-xl space-y-3">
            <div>
                <label class="label">Browser tab title</label>
                <input name="home[seo_title]" value="{{ $home['seo_title'] ?? '' }}" class="input" placeholder="{{ config('home.defaults.seo_title') }}">
                <p class="text-xs text-ink-700/50 mt-1">Shows as “<span class="font-medium">this — {{ store_name() }}</span>” in the browser tab and Google.</p>
            </div>
            <div>
                <label class="label">Google description</label>
                <textarea name="home[seo_description]" rows="2" class="input" maxlength="160" placeholder="{{ home_content('hero_subtitle') }}">{{ $home['seo_description'] ?? '' }}</textarea>
                <p class="text-xs text-ink-700/50 mt-1">The grey summary under your link in Google — about 155 characters. Leave empty to reuse the hero line.</p>
            </div>
        </div>
    </x-admin.home-panel>

    @include('admin.appearance.home.hero')

    <input type="hidden" name="section_order_present" value="1">
    <input type="hidden" name="home_blocks_present" value="1">
    <div class="space-y-3" x-ref="list" x-on:section-removed="renumber()">
        @foreach($homeOrder as $token)
            @if(\Illuminate\Support\Str::startsWith($token, 'block:'))
                @php $block = $blocksById->get(\Illuminate\Support\Str::after($token, 'block:')); @endphp
                @if($block)
                    @include('admin.appearance.home.block', ['xdata' => 'homeBlock('.\Illuminate\Support\Js::from($block).')'])
                @endif
            @elseif(config('home.sections.'.$token))
                @include('admin.appearance.home.section', ['key' => $token])
            @endif
        @endforeach
    </div>

    <template x-ref="blockTpl">
        @include('admin.appearance.home.block', ['xdata' => 'homeBlock({}, true)'])
    </template>

    <div class="card p-4 flex flex-wrap items-center gap-2">
        <span class="text-sm font-medium text-ink-800 mr-1">Add your own section:</span>
        <select x-model="newType" class="input py-1.5 w-60 text-sm">
            <option value="product_carousel">Product row</option>
            <option value="banner_carousel">Banner + products</option>
            <option value="banner">Promo banner(s)</option>
            <option value="cta_banner">Image banner with text</option>
            <option value="video">Videos</option>
            <option value="faq">Questions &amp; answers</option>
            <option value="reviews">Customer reviews (hand-picked)</option>
            <option value="richtext">Text / HTML</option>
        </select>
        <button type="button" @click="add()" class="btn-outline text-sm">+ Add section</button>
        <p class="w-full text-xs text-ink-700/50">It appears at the bottom of the list — drag it where you want it. Uploaded pictures fill in after you save.</p>
    </div>

    {{-- Settings only the older Blade homepage templates read. --}}
    <details class="card p-5">
        <summary class="cursor-pointer text-sm font-semibold text-ink-700">Settings for the classic designs <span class="font-normal text-ink-700/50">— only read by the older designs</span></summary>
        <div class="mt-4 space-y-5">
            <div>
                <div class="flex flex-wrap gap-4 text-sm mb-3">
                    <input type="hidden" name="home_show_highlights_present" value="1">
                    <label class="flex items-center gap-2"><input type="checkbox" name="home_show_highlights" value="1" @checked($home['show_highlights'] ?? true)> Highlighted categories</label>
                </div>
                @include('admin.partials._category-picker', [
                    'field' => 'highlight_category_ids',
                    'label' => 'Highlighted categories',
                    'help' => 'Large editorial banners on the older templates, in this order.',
                    'selectedIds' => $home['highlight_category_ids'] ?? [],
                    'allCategories' => $allCategories,
                ])
            </div>
        </div>
    </details>
</div>

{{-- Live preview: the homepage as the form stands, before Save. --}}
<aside class="hidden xl:block xl:sticky xl:top-[5.5rem]"
       :class="open ? '!fixed !inset-0 !z-[90] !block bg-ink-900/70 p-2 sm:p-4' : ''">
    <div class="card overflow-hidden flex flex-col h-[calc(100vh-7rem)]" :class="open ? '!h-full' : ''">
        <div class="flex items-center gap-2 px-3 py-2.5 border-b border-ink-100">
            <span class="text-sm font-semibold text-ink-900">Live preview</span>
            <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px]"
                  :class="{ 'bg-green-50 text-green-700': status === 'live', 'bg-amber-50 text-amber-700': status === 'pending' || status === 'updating', 'bg-red-50 text-red-700': status === 'error' }">
                <span class="w-1.5 h-1.5 rounded-full bg-current" :class="status === 'updating' && 'animate-pulse'"></span>
                <span x-text="{ live: 'Up to date', pending: 'Changes…', updating: 'Updating…', error: 'Could not update' }[status]"></span>
            </span>
            <div class="ml-auto inline-flex rounded-lg bg-ink-50 p-0.5 text-xs">
                <button type="button" @click="device = 'desktop'" :class="device === 'desktop' ? 'bg-white shadow-sm text-ink-900' : 'text-ink-700/60'" class="px-2.5 py-1 rounded-md">Computer</button>
                <button type="button" @click="device = 'phone'" :class="device === 'phone' ? 'bg-white shadow-sm text-ink-900' : 'text-ink-700/60'" class="px-2.5 py-1 rounded-md">Phone</button>
            </div>
            <a :href="src" target="_blank" class="text-xs text-gold-700 hover:underline px-1" title="Open this preview in a new tab">Open ↗</a>
            <button type="button" x-show="open" @click="open = false" class="grid place-items-center w-8 h-8 rounded-lg hover:bg-ink-50" aria-label="Close preview">&times;</button>
        </div>
        <div x-ref="box" class="relative flex-1 overflow-hidden bg-ink-100/60">
            <div class="absolute top-0 left-1/2 origin-top" :class="device === 'phone' ? 'mt-3' : ''"
                 :style="`width:${frameW}px;height:${device === 'phone' ? frameH - 24 : frameH}px;transform:translateX(-50%) scale(${scale})`">
                <iframe x-ref="frame" :src="src" @load="loaded()" title="Homepage preview"
                        class="w-full h-full bg-white" :class="device === 'phone' ? 'rounded-[28px] ring-8 ring-ink-900 shadow-2xl' : ''"></iframe>
            </div>
        </div>
        <p class="px-3 py-2 border-t border-ink-100 text-[11px] text-ink-700/55">Updates as you edit — nothing goes live until you press <strong>Save</strong>. Pictures uploaded from your device appear after saving; library picks show straight away.</p>
    </div>
</aside>

{{-- Below the wide-screen layout, the preview opens full-screen. --}}
<button type="button" x-show="!open" @click="open = true" class="xl:hidden fixed bottom-24 left-4 z-40 inline-flex items-center gap-2 rounded-full bg-ink-900 text-white px-4 py-2.5 text-sm shadow-lg">
    <span class="w-2 h-2 rounded-full" :class="status === 'live' ? 'bg-green-400' : 'bg-amber-400'"></span> Live preview
</button>
</div>

