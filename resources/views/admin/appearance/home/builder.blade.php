{{--
    The homepage builder (owner, 2026-10-24): every section of the homepage in
    the order it appears, each with an on/off switch and everything it shows.
    Built-in sections come from config('home.sections'); custom sections are
    the old "section builder" blocks, now placed among them.
--}}
@php
    $blocksById = collect($homeBlocks)->keyBy('id');
@endphp
<div class="space-y-4" x-show="tab==='homepage'" x-data="homeSections">
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
        <summary class="cursor-pointer text-sm font-semibold text-ink-700">Older homepage templates <span class="font-normal text-ink-700/50">— only needed if you switch away from the current design</span></summary>
        <div class="mt-4 space-y-5">
            <div>
                <h3 class="text-sm font-semibold text-ink-700 mb-1">Homepage design</h3>
                <p class="text-xs text-ink-700/60 mb-3">The current design is <strong>Couture</strong>. The builder above only shapes Couture.</p>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3" x-data="{ sel: '{{ $theme['homepage_template'] }}' }">
                    @foreach($homeTemplates as $key => $tpl)
                        <label class="cursor-pointer rounded-lg border p-4 transition"
                               :class="sel === '{{ $key }}' ? 'border-gold-500 bg-gold-50 ring-1 ring-gold-400' : 'border-ink-100 hover:border-gold-300'">
                            <input type="radio" name="homepage_template" value="{{ $key }}" class="sr-only" x-model="sel" @checked($theme['homepage_template'] == $key)>
                            <div class="font-medium text-sm">{{ $tpl['name'] }}</div>
                            <div class="text-xs text-ink-700/50 mt-1">Inspired by {{ $tpl['inspiration'] }}</div>
                            <a href="{{ route('home') }}?preview_home={{ $key }}" target="_blank" class="text-xs text-gold-700 hover:underline mt-2 inline-block">Preview ↗</a>
                        </label>
                    @endforeach
                </div>
            </div>
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
