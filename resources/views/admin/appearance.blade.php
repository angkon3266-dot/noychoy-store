@extends('layouts.admin')
@section('title', 'Appearance')
@section('heading', 'Appearance & Theme')

@section('content')
<form action="{{ route('admin.appearance.update') }}" method="POST" enctype="multipart/form-data" class="space-y-6 max-w-4xl pb-24"
      x-data="{ tab: localStorage.getItem('appearanceTab') || 'branding' }"
      x-init="$watch('tab', v => localStorage.setItem('appearanceTab', v))">
    @csrf

    @if($errors->any())
        <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-700">
            <p class="font-semibold mb-1">Couldn't save — please fix:</p>
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif
    @if(session('success'))
        <div class="rounded-lg border border-green-300 bg-green-50 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    {{-- ── Appearance tabs ──────────────────────────────────────────────── --}}
    <div class="flex flex-wrap gap-1 bg-ink-50 rounded-lg p-1 sticky top-2 z-30 text-sm">
        @foreach(['branding'=>'Branding','homepage'=>'Homepage','sections'=>'Section Builder','filters'=>'Filters'] as $t => $lbl)
            <button type="button" @click="tab='{{ $t }}'"
                    :class="tab==='{{ $t }}' ? 'bg-white shadow-sm text-gold-800 font-medium' : 'text-ink-700/60 hover:text-ink-700'"
                    class="px-4 py-2 rounded-md">{{ $lbl }}</button>
        @endforeach
    </div>

    <!-- Branding -->
    <div class="card p-6" x-show="tab==='branding'">
        <h2 class="font-semibold mb-4">Branding</h2>
        <div class="grid sm:grid-cols-2 gap-6">
            <div>
                <x-media-field name="logo" :value="theme_asset($theme['logo']) ?: ''" folder="branding"
                    label="Logo"
                    help="Desktop logo. PNG with transparent background recommended. Leave empty to use the text logo." />

                <div class="mt-3">
                    <x-media-field name="logo_mobile" :value="theme_asset($theme['logo_mobile'] ?? null) ?: ''" folder="branding"
                        label="Mobile logo"
                        help="Optional. When set, this is used instead of the desktop logo on phones." />
                </div>

                <div class="grid grid-cols-3 gap-3 mt-3">
                    <div><label class="label text-xs">Logo height — desktop (px)</label><input type="number" name="logo_height_desktop" value="{{ $theme['logo_height_desktop'] ?? 40 }}" min="16" max="120" class="input text-sm"></div>
                    <div><label class="label text-xs">Logo height — mobile (px)</label><input type="number" name="logo_height_mobile" value="{{ $theme['logo_height_mobile'] ?? 32 }}" min="16" max="100" class="input text-sm"></div>
                    <div>
                        <label class="label text-xs">Logo placement</label>
                        <select name="logo_align" class="input text-sm">
                            @foreach(['left'=>'Left','center'=>'Middle','right'=>'Right'] as $v=>$lbl)
                                <option value="{{ $v }}" @selected(($theme['logo_align'] ?? 'left')===$v)>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-4">
                    <x-media-field name="header_center_image" :value="theme_asset($theme['header_center_image'] ?? null) ?: ''" folder="branding"
                        label="Mobile center image (optional)" />
                </div>
                <div class="grid grid-cols-2 gap-3 mt-2">
                    <div><label class="label text-xs">Center image link</label><input name="header_center_link" value="{{ $theme['header_center_link'] ?? '' }}" class="input text-sm" placeholder="(optional)"></div>
                    <div><label class="label text-xs">Center image height (px)</label><input type="number" name="header_center_height" value="{{ $theme['header_center_height'] ?? 32 }}" min="16" max="100" class="input text-sm"></div>
                </div>
                <p class="text-xs text-ink-700/50 mt-1">Shows centered in the mobile header (e.g. a badge or campaign mark).</p>

                <div class="mt-4">
                    <x-media-field name="menu_icon" :value="theme_asset($theme['menu_icon'] ?? null) ?: ''" folder="branding"
                        label="Mobile menu icon" />
                </div>
                <div class="grid grid-cols-2 gap-3 mt-2">
                    <div><label class="label text-xs">Rotation when menu opens (°)</label><input type="number" name="menu_icon_rotation" value="{{ $theme['menu_icon_rotation'] ?? 45 }}" min="0" max="360" class="input text-sm"></div>
                    <div><label class="label text-xs">Icon size (px)</label><input type="number" name="menu_icon_height" value="{{ $theme['menu_icon_height'] ?? 28 }}" min="16" max="80" class="input text-sm"></div>
                </div>
                <p class="text-xs text-ink-700/50 mt-1">Used as the mobile menu toggle (e.g. your "M" mark). Tapping opens the menu and rotates the icon by this angle over 300ms; tapping again closes &amp; rotates back. Mobile only.</p>
            </div>
            <div>
                <x-media-field name="favicon" :value="theme_asset($theme['favicon']) ?: ''" folder="branding"
                    label="Favicon" />
            </div>
            @php
                $colorRoles = [
                    'primary' => ['Primary', 'Buttons, links, prices'],
                    'accent' => ['Accent', 'Secondary highlights & progress bars'],
                    'background' => ['Background', 'Page background & light surfaces'],
                    'text' => ['Text / Ink', 'Headings & body text'],
                ];
            @endphp
            @foreach($colorRoles as $key => [$label, $hint])
                <div x-data="{ c: '{{ $theme[$key] ?? '#000000' }}' }">
                    <label class="label">{{ $label }} colour</label>
                    <div class="flex items-center gap-2">
                        <input type="color" name="{{ $key }}" x-model="c" class="h-10 w-14 rounded border border-ink-100">
                        <input type="text" x-model="c" class="input py-1.5 w-28 font-mono text-xs uppercase">
                    </div>
                    <p class="text-xs text-ink-700/50 mt-1">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        {{-- One-click 4-colour preset palettes --}}
        <div class="mt-6">
            <label class="label">Quick palettes</label>
            <p class="text-xs text-ink-700/50 mb-3">Click one to set all four colours, then Save. Fine-tune above afterwards.</p>
            <div class="flex flex-wrap gap-3">
                @foreach(config('theme.palettes', []) as $key => $pal)
                    <button type="button"
                        onclick="['primary','accent','background','text'].forEach(k=>{const v={primary:'{{ $pal['primary'] }}',accent:'{{ $pal['accent'] }}',background:'{{ $pal['background'] }}',text:'{{ $pal['text'] }}'}[k];document.querySelectorAll('[name='+k+']').forEach(e=>{e.value=v;e.dispatchEvent(new Event('input'))})});"
                        class="group flex items-center gap-2 rounded-lg border border-ink-100 px-3 py-2 hover:border-gold-400 hover:shadow-sm transition">
                        <span class="flex -space-x-1">
                            <span class="h-6 w-6 rounded-full border-2 border-white shadow" style="background: {{ $pal['primary'] }}"></span>
                            <span class="h-6 w-6 rounded-full border-2 border-white shadow" style="background: {{ $pal['accent'] }}"></span>
                            <span class="h-6 w-6 rounded-full border-2 border-white shadow" style="background: {{ $pal['background'] }}"></span>
                            <span class="h-6 w-6 rounded-full border-2 border-white shadow" style="background: {{ $pal['text'] }}"></span>
                        </span>
                        <span class="text-sm">{{ $pal['label'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Fonts --}}
    <div class="card p-6" x-show="tab==='branding'">
        <h2 class="font-semibold mb-1">Fonts</h2>
        <p class="text-xs text-ink-700/60 mb-4">Pick a Google font, or upload your own brand font file (.woff2/.woff/.ttf/.otf) — e.g. <strong>Blore</strong> for headings.</p>
        <div class="grid sm:grid-cols-2 gap-6">
            @foreach(['heading' => 'Heading font', 'body' => 'Body font'] as $slot => $slotLabel)
                @php
                    $curName = $theme['font_'.$slot] ?? '';
                    $curSrc = $theme['font_'.$slot.'_src'] ?? 'google';
                    $curFile = $theme['font_'.$slot.'_file'] ?? null;
                @endphp
                <div x-data="{ src: '{{ $curSrc }}' }">
                    <label class="label">{{ $slotLabel }}</label>
                    <div class="flex gap-2 mb-2">
                        <label class="flex items-center gap-1.5 text-sm"><input type="radio" name="font_{{ $slot }}_src" value="google" x-model="src"> Google font</label>
                        <label class="flex items-center gap-1.5 text-sm"><input type="radio" name="font_{{ $slot }}_src" value="custom" x-model="src"> Upload</label>
                    </div>
                    <div x-show="src==='google'">
                        <select name="font_{{ $slot }}" class="input" :disabled="src!=='google'">
                            @foreach(config('theme.fonts', []) as $f)
                                <option value="{{ $f }}" @selected($curName===$f)>{{ $f }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div x-show="src==='custom'" x-cloak class="space-y-2">
                        <input name="font_{{ $slot }}" :disabled="src!=='custom'" value="{{ $curSrc==='custom' ? $curName : '' }}" class="input" placeholder="Font name (e.g. Blore)">
                        <input type="file" name="font_{{ $slot }}_file" accept=".woff,.woff2,.ttf,.otf" class="input text-sm">
                        @if($curFile)<p class="text-xs text-green-700">Current: {{ basename($curFile) }}</p>@endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Announcement bar -->
    <div class="card p-6" x-show="tab==='branding'">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-semibold">Announcement top bar</h2>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="announcement_enabled" value="1" @checked($theme['announcement_enabled'])> Enabled</label>
        </div>
        <label class="label">Offer messages — one per line</label>
        <textarea name="announcement_messages" rows="4" class="input" placeholder="Free delivery on orders over ৳3000&#10;Cash on delivery available&#10;Eid sale — up to 30% off">{{ implode("\n", (array) $theme['announcement_messages']) }}</textarea>
        <p class="text-xs text-ink-700/50 mt-1">These scroll continuously across the top bar. Add as many offers as you like — one per line.</p>
        <div class="grid sm:grid-cols-4 gap-4 mt-4">
            <div><label class="label">Background</label><input type="color" name="announcement_bg" value="{{ $theme['announcement_bg'] }}" class="h-10 w-14 rounded border border-ink-100"></div>
            <div><label class="label">Text colour</label><input type="color" name="announcement_color" value="{{ $theme['announcement_color'] }}" class="h-10 w-14 rounded border border-ink-100"></div>
            <div><label class="label">Speed (sec/msg)</label><input type="number" name="announcement_speed" min="2" max="30" value="{{ $theme['announcement_speed'] ?? 6 }}" class="input"></div>
            <div><label class="label">Link (optional)</label><input name="announcement_link" value="{{ $theme['announcement_link'] }}" class="input" placeholder="/shop"></div>
        </div>
    </div>

    <!-- Templates -->
    <div class="card p-6" x-show="tab==='homepage'">
        <h2 class="font-semibold mb-1">Homepage template</h2>
        <p class="text-xs text-ink-700/60 mb-4">Pick a brand-inspired layout, then <strong>Save</strong> to apply. You can change anytime.</p>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3" x-data="{ sel: '{{ $theme['homepage_template'] }}' }">
            @foreach($homeTemplates as $key => $tpl)
                <label class="cursor-pointer rounded-lg border p-4 transition"
                       :class="sel === '{{ $key }}' ? 'border-gold-500 bg-gold-50 ring-1 ring-gold-400' : 'border-ink-100 hover:border-gold-300'">
                    <input type="radio" name="homepage_template" value="{{ $key }}" class="sr-only" x-model="sel" @checked($theme['homepage_template']==$key)>
                    <div class="flex items-center justify-between">
                        <div class="font-medium text-sm">{{ $tpl['name'] }}</div>
                        <svg x-show="sel === '{{ $key }}'" class="w-4 h-4 text-gold-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    </div>
                    <div class="text-xs text-ink-700/50 mt-1">Inspired by {{ $tpl['inspiration'] }}</div>
                    <a href="{{ route('home') }}?preview_home={{ $key }}" target="_blank" class="text-xs text-gold-700 hover:underline mt-2 inline-block">Preview ↗</a>
                </label>
            @endforeach
        </div>

        <h2 class="font-semibold mb-1 mt-8">Product page template</h2>
        <p class="text-xs text-ink-700/60 mb-4">Default for all products. Per-category overrides are set on each category.</p>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3" x-data="{ sel: '{{ $theme['product_template'] }}' }">
            @foreach($productTemplates as $key => $tpl)
                <label class="cursor-pointer rounded-lg border p-4 transition"
                       :class="sel === '{{ $key }}' ? 'border-gold-500 bg-gold-50 ring-1 ring-gold-400' : 'border-ink-100 hover:border-gold-300'">
                    <input type="radio" name="product_template" value="{{ $key }}" class="sr-only" x-model="sel" @checked($theme['product_template']==$key)>
                    <div class="flex items-center justify-between">
                        <div class="font-medium text-sm">{{ $tpl['name'] }}</div>
                        <svg x-show="sel === '{{ $key }}'" class="w-4 h-4 text-gold-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    </div>
                    <div class="text-xs text-ink-700/50 mt-1">Inspired by {{ $tpl['inspiration'] }}</div>
                </label>
            @endforeach
        </div>
    </div>

    <!-- Homepage content -->
    <div class="card p-6" x-show="tab==='homepage'">
        <h2 class="font-semibold mb-1">Homepage content</h2>
        <p class="text-xs text-ink-700/60 mb-4">Edit the text, links and image shown on your homepage hero &amp; sections. Applies to whichever homepage template is active. Leave a field empty to use the default.</p>

        <h3 class="text-sm font-semibold text-ink-700 mt-2 mb-3">Hero banner</h3>
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="label">Eyebrow (small text above heading)</label><input name="home[hero_eyebrow]" value="{{ $home['hero_eyebrow'] }}" class="input" placeholder="{{ config('store.name') }}"></div>
            <div><label class="label">Highlighted phrase (coloured part of heading)</label><input name="home[hero_highlight]" value="{{ $home['hero_highlight'] }}" class="input" placeholder="your story"></div>
            <div class="sm:col-span-2"><label class="label">Heading</label><input name="home[hero_heading]" value="{{ $home['hero_heading'] }}" class="input"></div>
            <div class="sm:col-span-2"><label class="label">Subtitle</label><textarea name="home[hero_subtitle]" rows="2" class="input">{{ $home['hero_subtitle'] }}</textarea></div>
            <div><label class="label">Primary button text</label><input name="home[hero_cta_text]" value="{{ $home['hero_cta_text'] }}" class="input"></div>
            <div><label class="label">Primary button link</label><input name="home[hero_cta_link]" value="{{ $home['hero_cta_link'] }}" class="input" placeholder="(defaults to Shop page)"></div>
            <div><label class="label">Secondary button text</label><input name="home[hero_secondary_text]" value="{{ $home['hero_secondary_text'] }}" class="input" placeholder="(leave empty to hide)"></div>
            <div><label class="label">Secondary button link</label><input name="home[hero_secondary_link]" value="{{ $home['hero_secondary_link'] }}" class="input" placeholder="(defaults to Track page)"></div>
        </div>
        <div class="mt-4">
            <x-media-field name="hero_image" :value="theme_asset($home['hero_image']) ?: ''" folder="branding"
                label="Hero background image (optional)"
                help="Used as the hero background on Aurelia / Maison and the side image on Lumière. Wide image (e.g. 1600×900) recommended." />
        </div>

        <h3 class="text-sm font-semibold text-ink-700 mt-6 mb-3">Section titles</h3>
        <div class="grid sm:grid-cols-3 gap-4">
            <div><label class="label">Categories</label><input name="home[categories_title]" value="{{ $home['categories_title'] }}" class="input"></div>
            <div><label class="label">Featured</label><input name="home[featured_title]" value="{{ $home['featured_title'] }}" class="input"></div>
            <div><label class="label">New arrivals</label><input name="home[new_arrivals_title]" value="{{ $home['new_arrivals_title'] }}" class="input"></div>
        </div>

        <h3 class="text-sm font-semibold text-ink-700 mt-6 mb-3">Trust badges (bottom strip)</h3>
        <div class="grid sm:grid-cols-3 gap-4">
            @for($b = 1; $b <= 3; $b++)
                <div class="rounded-lg border border-ink-100 p-3 space-y-2">
                    <input name="home[badge{{ $b }}_title]" value="{{ $home['badge'.$b.'_title'] }}" class="input" placeholder="Title">
                    <input name="home[badge{{ $b }}_text]" value="{{ $home['badge'.$b.'_text'] }}" class="input" placeholder="Subtitle">
                </div>
            @endfor
        </div>

        {{-- "Our promise" editorial brand band (Couture template) --}}
        <div class="mt-6 border-t border-ink-100 pt-5">
            <label class="flex items-center gap-2 text-sm font-semibold text-ink-700 mb-1"><input type="checkbox" name="home_show_promise" value="1" @checked($home['show_promise'] ?? true)> Show the “Our promise” brand band</label>
            <p class="text-xs text-ink-700/50 mb-3">The image + text band on the Couture homepage. Leave the image empty to auto-use your newest product photo.</p>
            <div class="grid sm:grid-cols-2 gap-4">
                <div><label class="label">Eyebrow (small text)</label><input name="home[promise_eyebrow]" value="{{ $home['promise_eyebrow'] ?? '' }}" class="input" placeholder="Our promise"></div>
                <div><label class="label">Heading</label><input name="home[promise_title]" value="{{ $home['promise_title'] ?? '' }}" class="input" placeholder="Crafted to be treasured"></div>
                <div class="sm:col-span-2"><label class="label">Text</label><textarea name="home[promise_text]" rows="2" class="input" placeholder="(defaults to the hero subtitle)">{{ $home['promise_text'] ?? '' }}</textarea></div>
            </div>
            <div class="mt-4">
                <x-media-field name="promise_image" :value="theme_asset($home['promise_image'] ?? null) ?: ''" folder="branding"
                    label="Band image" help="Square-ish image (e.g. 1000×800) works best. Leave empty to use your newest product photo." />
            </div>
        </div>
    </div>

    <!-- Homepage sections (Storefront template) -->
    <div class="card p-6" x-show="tab==='homepage'">
        <h2 class="font-semibold mb-1">Homepage sections</h2>
        <p class="text-xs text-ink-700/60 mb-4">Build the storefront homepage — hero slider, feature strip, best sellers, new arrivals, highlighted categories and videos. Toggle each section on/off.</p>

        {{-- Toggles --}}
        <div class="grid sm:grid-cols-3 gap-2 mb-6 text-sm">
            @foreach(['show_feature_strip'=>'Feature strip','show_categories'=>'Category scroller','show_best_selling'=>'Best selling','show_new_arrivals'=>'New arrivals','show_highlights'=>'Highlighted categories'] as $k => $lbl)
                <label class="flex items-center gap-2"><input type="checkbox" name="home_{{ $k }}" value="1" @checked($home[$k] ?? true)> {{ $lbl }}</label>
            @endforeach
        </div>

        {{-- Category scroller (ordered pick) --}}
        <div class="mb-6">
            <h3 class="text-sm font-semibold text-ink-700 mb-2">Category scroller</h3>
            @include('admin.partials._category-picker', [
                'field' => 'category_scroller_ids',
                'label' => '',
                'help' => 'Which categories appear in the homepage category scroller, in this order. Leave empty to auto-show your top categories.',
                'selectedIds' => $home['category_scroller_ids'] ?? [],
                'allCategories' => $allCategories,
            ])
        </div>

        {{-- Best-selling title --}}
        <div class="mb-6"><label class="label">Best-selling section title</label><input name="home[best_selling_title]" value="{{ $home['best_selling_title'] ?? '' }}" class="input"></div>

        {{-- Hero slides --}}
        <h3 class="text-sm font-semibold text-ink-700 mb-2">Hero slider</h3>
        @php $slides = collect($home['hero_slides'] ?? [])->filter(fn($s)=>filled($s['image'] ?? null))->values(); @endphp
        @if($slides->isNotEmpty())
            <div class="space-y-3 mb-3">
                @foreach($slides as $i => $s)
                    @php $simg = \Illuminate\Support\Str::startsWith($s['image'],['http','/']) ? $s['image'] : \Illuminate\Support\Facades\Storage::disk('public')->url($s['image']); @endphp
                    <div class="flex items-center gap-3 rounded-lg border border-ink-100 p-2">
                        <img src="{{ $simg }}" class="w-24 h-14 object-cover rounded" alt="">
                        <input name="hero_slides[{{ $i }}][link]" value="{{ $s['link'] ?? '' }}" class="input flex-1" placeholder="Link when clicked (optional)">
                        <label class="flex items-center gap-1 text-xs text-red-600"><input type="checkbox" name="hero_slides[{{ $i }}][remove]" value="1"> Remove</label>
                    </div>
                @endforeach
            </div>
        @endif
        <label class="label">Add slide images</label>
        @php $existingSlides = $slides->count(); @endphp
        <div x-data="{ rows: 1, max: Math.max(1, 10 - {{ $existingSlides }}), picks: [] }">
            {{-- Media-library picks (added as new slides on Save) --}}
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
            <button type="button" @click="if (rows < max) rows++" x-show="rows < max"
                    class="mt-1 inline-flex items-center gap-1 text-sm text-gold-700 font-medium hover:text-gold-800">
                <span class="text-lg leading-none">＋</span> Add another image <span class="text-ink-700/40" x-text="'(' + rows + '/' + max + ')'"></span>
            </button>
            <p class="text-xs text-ink-700/50 mt-2">Add up to 10 slides total. Wide images work best (e.g. 1920×800). Links are editable after saving.</p>
        </div>

        {{-- Feature strip --}}
        <h3 class="text-sm font-semibold text-ink-700 mt-6 mb-2">Feature strip (reassurance icons)</h3>
        <div x-data="{ rows: @js(array_values($home['feature_strip'] ?? [])) }">
            <template x-for="(r, i) in rows" :key="i">
                <div class="flex gap-2 mb-2">
                    <input :name="`feature_strip[${i}][icon]`" x-model="r.icon" class="input w-16 text-center" placeholder="🚚" maxlength="4">
                    <input :name="`feature_strip[${i}][title]`" x-model="r.title" class="input flex-1" placeholder="Fastest Shipping Countrywide">
                    <button type="button" @click="rows.splice(i,1)" class="text-red-500 px-2 text-xl leading-none">&times;</button>
                </div>
            </template>
            <button type="button" @click="rows.push({icon:'✓',title:''})" class="btn-outline text-sm">+ Add feature</button>
        </div>

        {{-- Highlighted categories (ordered) --}}
        <h3 class="text-sm font-semibold text-ink-700 mt-6 mb-2">Highlighted categories</h3>
        @include('admin.partials._category-picker', [
            'field' => 'highlight_category_ids',
            'label' => '',
            'help' => 'Featured as large editorial banners, in the order below. Add the ones you want and drag with ↑/↓.',
            'selectedIds' => $home['highlight_category_ids'] ?? [],
            'allCategories' => $allCategories,
        ])

    </div>

    <!-- Section builder -->
    <div class="card p-6" x-show="tab==='sections'" x-data="homeBuilder({ blocks: @js(array_values($home['sections'] ?? [])) })">
        <h2 class="font-semibold mb-1">Section builder</h2>
        <p class="text-xs text-ink-700/60 mb-4">Build the middle of your homepage from blocks. Drag order with ↑/↓. When you add blocks here they replace the default sections above (hero &amp; feature strip stay on top).</p>
        <input type="hidden" name="home_sections_json" :value="JSON.stringify(blocks)">

        <template x-for="(b, bi) in blocks" :key="bi">
            <div class="rounded-lg border border-ink-200 p-3 mb-3" x-init="ensure(b)">
                <div class="flex items-center gap-2 mb-2">
                    <select x-model="b.type" class="input py-1.5 w-48 text-sm">
                        <option value="banner">Promo banner(s)</option>
                        <option value="product_carousel">Product carousel</option>
                        <option value="banner_carousel">Banner + products</option>
                        <option value="video">Video row</option>
                        <option value="richtext">Rich text / HTML</option>
                    </select>
                    <input x-model="b.title" class="input py-1.5 flex-1 text-sm" placeholder="Section title (optional)">
                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" x-model="b.enabled"> On</label>
                    <button type="button" @click="move(bi,-1)" class="px-2">↑</button>
                    <button type="button" @click="move(bi,1)" class="px-2">↓</button>
                    <button type="button" @click="remove(bi)" class="px-2 text-red-500 text-lg">&times;</button>
                </div>

                {{-- Banner --}}
                <div x-show="b.type==='banner'" class="space-y-2">
                    <select x-model="b.layout" class="input py-1.5 w-40 text-sm">
                        <option value="single">Single (full width)</option>
                        <option value="dual">Two side-by-side</option>
                        <option value="grid">Grid (3)</option>
                    </select>
                    <template x-for="(im, ii) in b.images" :key="ii">
                        <div class="flex gap-2 items-center">
                            <template x-if="im.image">
                                <img :src="im.image.startsWith('http') || im.image.startsWith('/') ? im.image : '/storage/'+im.image" class="w-10 h-10 object-cover rounded border border-ink-100 shrink-0">
                            </template>
                            <input x-model="im.image" class="input py-1.5 text-sm flex-1" placeholder="image path or URL">
                            <button type="button" @click="$store.mediaLib.openWith(u => im.image = u, 'sections')" class="btn-outline py-1 text-xs shrink-0">Library</button>
                            <input type="file" :name="`block_image[${bi}][${ii}]`" accept="image/*" class="text-xs w-32">
                            <input x-model="im.link" class="input py-1.5 text-sm w-36" placeholder="link">
                            <button type="button" @click="b.images.splice(ii,1)" class="text-red-500 px-1">&times;</button>
                        </div>
                    </template>
                    <button type="button" @click="addImage(b)" class="btn-outline py-1 text-xs">+ Add image</button>
                </div>

                {{-- Product carousel / Banner+products shared source controls --}}
                <div x-show="b.type==='product_carousel' || b.type==='banner_carousel'" class="flex flex-wrap gap-2 items-end mt-1">
                    <div><label class="label text-xs">Source</label>
                        <select x-model="b.source" class="input py-1.5 text-sm">
                            <option value="new">Newest</option><option value="best">Best sellers</option>
                            <option value="featured">Featured</option><option value="category">Category</option>
                        </select>
                    </div>
                    <div x-show="b.source==='category'"><label class="label text-xs">Category</label>
                        <select x-model="b.category_id" class="input py-1.5 text-sm">
                            <option value="">Choose…</option>
                            @foreach($allCategories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
                        </select>
                    </div>
                    <div><label class="label text-xs">Max items</label><input type="number" x-model="b.limit" min="1" max="20" class="input py-1.5 w-20 text-sm"></div>
                    <div><label class="label text-xs">"View all" link</label><input x-model="b.view_all_link" class="input py-1.5 text-sm w-48" placeholder="(optional)"></div>
                </div>

                {{-- Banner image for banner_carousel --}}
                <div x-show="b.type==='banner_carousel'" class="flex gap-2 items-center mt-2">
                    <input x-model="b.banner.image" class="input py-1.5 text-sm flex-1" placeholder="side banner image path/URL">
                    <button type="button" @click="$store.mediaLib.openWith(u => b.banner.image = u, 'sections')" class="btn-outline py-1 text-xs shrink-0">Library</button>
                    <input type="file" :name="`block_banner[${bi}]`" accept="image/*" class="text-xs w-32">
                    <input x-model="b.banner.link" class="input py-1.5 text-sm w-36" placeholder="link">
                </div>

                {{-- Video --}}
                <div x-show="b.type==='video'" class="space-y-2">
                    <template x-for="(v, vi) in b.videos" :key="vi">
                        <div class="flex gap-2">
                            <input x-model="v.title" class="input py-1.5 text-sm w-44" placeholder="Title">
                            <input x-model="v.url" class="input py-1.5 text-sm flex-1" placeholder="YouTube link or MP4 path">
                            <button type="button" @click="b.videos.splice(vi,1)" class="text-red-500 px-1">&times;</button>
                        </div>
                    </template>
                    <button type="button" @click="addVideo(b)" class="btn-outline py-1 text-xs">+ Add video</button>
                    <p class="text-xs text-ink-700/50">Upload MP4s in the section above (Homepage sections) or paste links here.</p>
                </div>

                {{-- Rich text --}}
                <div x-show="b.type==='richtext'">
                    <textarea x-model="b.html" rows="4" class="input text-sm font-mono" placeholder="<h2>Custom HTML…</h2>"></textarea>
                </div>
            </div>
        </template>

        <div class="flex items-center gap-2 mt-2">
            <select x-model="newType" class="input py-1.5 w-48 text-sm">
                <option value="product_carousel">Product carousel</option>
                <option value="banner">Promo banner(s)</option>
                <option value="banner_carousel">Banner + products</option>
                <option value="video">Video row</option>
                <option value="richtext">Rich text / HTML</option>
            </select>
            <button type="button" @click="add()" class="btn-outline text-sm">+ Add section</button>
        </div>
        <p class="text-xs text-ink-700/50 mt-2">Tip: upload a banner image, then Save — the stored path fills in automatically.</p>
    </div>

    <!-- Registered-customer offer bar -->
    <div class="card p-6" x-show="tab==='branding'">
        <div class="flex items-center justify-between mb-1">
            <h2 class="font-semibold">Registered-customer offer bar</h2>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="cbar_enabled" value="1" @checked($theme['cbar_enabled'] ?? false)> Enabled</label>
        </div>
        <p class="text-xs text-ink-700/60 mb-4">A personalised bar shown <strong>only to logged-in customers</strong>, just below the header. Use <code>{name}</code> to greet them by their first name. Great for member-only offers.</p>
        <div class="space-y-4">
            <div><label class="label">Message</label><input name="cbar_text" value="{{ $theme['cbar_text'] ?? '' }}" class="input" placeholder="Welcome back, {name}! Enjoy 10% off today 🎁"></div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div><label class="label">Promo code (optional)</label><input name="cbar_code" value="{{ $theme['cbar_code'] ?? '' }}" class="input font-mono" placeholder="MEMBER10"></div>
                <div><label class="label">Button label</label><input name="cbar_link_label" value="{{ $theme['cbar_link_label'] ?? 'Shop now' }}" class="input"></div>
            </div>
            <div><label class="label">Button link (optional)</label><input name="cbar_link" value="{{ $theme['cbar_link'] ?? '' }}" class="input" placeholder="/shop"></div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div><label class="label">Background</label><input type="color" name="cbar_bg" value="{{ $theme['cbar_bg'] ?? '#161618' }}" class="h-10 w-14 rounded border border-ink-100"></div>
                <div><label class="label">Text colour</label><input type="color" name="cbar_color" value="{{ $theme['cbar_color'] ?? '#f5edda' }}" class="h-10 w-14 rounded border border-ink-100"></div>
            </div>
            <p class="text-xs text-ink-700/50">Tip: if you set a promo code, customers can tap it to copy. The bar is dismissible and reappears whenever you change the message or code.</p>
        </div>
    </div>

    <!-- Discover page tiles (mobile bottom-nav "Discover") -->
    <div class="card p-6" x-show="tab==='homepage'" x-data="discoverBuilder({ tiles: @js(array_values($discoverTiles ?? [])) })">
        <h2 class="font-semibold mb-1">Discover page</h2>
        <p class="text-xs text-ink-700/60 mb-4">The tiles shown on the mobile <strong>Discover</strong> tab. Each tile has an image, a name and a link (a category, a product, or any URL). Leave it empty to fall back to your top categories.</p>
        <input type="hidden" name="discover_tiles_json" :value="JSON.stringify(tiles)">

        <template x-for="(t, i) in tiles" :key="i">
            <div class="rounded-lg border border-ink-200 p-3 mb-3 flex flex-wrap items-end gap-2">
                <div class="w-16 shrink-0">
                    <template x-if="t.image"><img :src="t.image.startsWith('http') || t.image.startsWith('/') ? t.image : '/storage/'+t.image" class="w-16 h-16 object-cover rounded bg-ink-50"></template>
                    <template x-if="!t.image"><div class="w-16 h-16 rounded bg-ink-50 flex items-center justify-center text-ink-300 text-xl">🖼</div></template>
                </div>
                <div class="flex-1 min-w-[8rem]"><label class="label text-xs">Name</label><input x-model="t.name" class="input py-1.5 text-sm" placeholder="e.g. Necklaces"></div>
                <div class="flex-1 min-w-[10rem]"><label class="label text-xs">Link</label><input x-model="t.link" class="input py-1.5 text-sm" placeholder="/category/necklaces"></div>
                <div><label class="label text-xs">Image</label><input type="file" :name="`discover_image[${i}]`" accept="image/*" class="text-xs w-36"></div>
                <div class="flex gap-1">
                    <button type="button" @click="move(i,-1)" class="px-2">↑</button>
                    <button type="button" @click="move(i,1)" class="px-2">↓</button>
                    <button type="button" @click="remove(i)" class="px-2 text-red-500 text-lg">&times;</button>
                </div>
            </div>
        </template>
        <button type="button" @click="add()" class="btn-outline text-sm">+ Add tile</button>
        <p class="text-xs text-ink-700/50 mt-2">Tip: square images (e.g. 600×600) look best. Upload an image then Save — the stored path fills in automatically.</p>
    </div>

    <!-- Floating contact buttons -->
    <div class="card p-6" x-show="tab==='branding'">
        <h2 class="font-semibold mb-1">Floating contact buttons</h2>
        <p class="text-xs text-ink-700/60 mb-4">Sticky buttons bottom-right of the storefront. The Call button dials your <a href="{{ route('admin.settings') }}" class="text-gold-700 underline">store phone</a>; on mobile it opens the dialer.</p>
        <div class="grid sm:grid-cols-3 gap-2 text-sm mb-4">
            <label class="flex items-center gap-2"><input type="checkbox" name="show_call_button" value="1" @checked($theme['show_call_button'] ?? true)> Call now</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_whatsapp_button" value="1" @checked($theme['show_whatsapp_button'] ?? true)> WhatsApp</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_messenger_button" value="1" @checked($theme['show_messenger_button'] ?? false)> Messenger</label>
        </div>
        <div><label class="label">Messenger link (m.me/yourpage)</label><input name="messenger_url" value="{{ $theme['messenger_url'] ?? '' }}" class="input" placeholder="https://m.me/yourpage"></div>
    </div>

    <!-- Storefront filters -->
    <div class="card p-6" x-show="tab==='filters'">
        <h2 class="font-semibold mb-1">Storefront filters</h2>
        <p class="text-xs text-ink-700/60 mb-4">Choose which filters appear in the shop sidebar. Values are pulled automatically from your products. "Color" attributes show colour swatches.</p>

        <div class="mb-4 max-w-xs">
            <label class="label">Products per page</label>
            <input type="number" name="products_per_page" min="1" max="200" value="{{ $theme['products_per_page'] ?? 20 }}" class="input">
            <p class="text-xs text-ink-700/50 mt-1">How many products show per page on the shop &amp; category pages. Default 20.</p>
        </div>

        <label class="label">Categories to show as a filter</label>
        <p class="text-xs text-ink-700/50 mb-1">Pick which categories appear in the sidebar "Category" filter. Leave all unchecked to show every active category.</p>
        @if($allCategories->isEmpty())
            <p class="text-xs text-ink-700/50 mb-4">No categories yet.</p>
        @else
            <div class="grid sm:grid-cols-3 gap-2 text-sm mb-4">
                @foreach($allCategories as $cat)
                    <label class="flex items-center gap-2"><input type="checkbox" name="filter_categories[]" value="{{ $cat->id }}" @checked(in_array($cat->id, $filterConfig['categories'] ?? []))> {{ $cat->name }}</label>
                @endforeach
            </div>
        @endif

        <div class="grid sm:grid-cols-3 gap-2 text-sm mb-4">
            <label class="flex items-center gap-2"><input type="checkbox" name="filter_category" value="1" @checked($filterConfig['category'] ?? true)> Category</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="filter_colors" value="1" @checked($filterConfig['colors'] ?? true)> Colours</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="filter_price" value="1" @checked($filterConfig['price'] ?? true)> Price ranges</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="filter_in_stock" value="1" @checked($filterConfig['in_stock'] ?? true)> In-stock</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="filter_on_sale" value="1" @checked($filterConfig['on_sale'] ?? true)> On-sale</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="filter_tags" value="1" @checked($filterConfig['tags'] ?? false)> Tags</label>
        </div>

        <label class="label">Variation attributes to show as filters</label>
        @if($filterAttributes->isEmpty())
            <p class="text-xs text-ink-700/50">No variation attributes found yet. Add variable products with attributes (e.g. Color, Size) and they'll appear here.</p>
        @else
            <div class="grid sm:grid-cols-3 gap-2 text-sm">
                @foreach($filterAttributes as $attr)
                    <label class="flex items-center gap-2"><input type="checkbox" name="filter_attributes[]" value="{{ $attr }}" @checked(in_array($attr, $filterConfig['attributes'] ?? []))> {{ $attr }}</label>
                @endforeach
            </div>
        @endif

        @if($filterCustomFields->isNotEmpty())
            <label class="label mt-4">Custom fields to show as filters</label>
            <div class="grid sm:grid-cols-3 gap-2 text-sm">
                @foreach($filterCustomFields as $cf)
                    <label class="flex items-center gap-2"><input type="checkbox" name="filter_custom_fields[]" value="{{ $cf }}" @checked(in_array($cf, $filterConfig['custom_fields'] ?? []))> {{ $cf }}</label>
                @endforeach
            </div>
        @endif

        <label class="label mt-4">Price ranges (one per line, "min-max")</label>
        <textarea name="filter_price_ranges" rows="4" class="input font-mono text-sm">{{ collect($filterConfig['price_ranges'] ?? [])->map(fn($r) => $r[0].'-'.$r[1])->implode("\n") }}</textarea>
    </div>

    <!-- Per-page filter overrides -->
    <div class="card p-6" x-show="tab==='filters'"
         x-data="filterOverrides(@js($overridablePages), @js((object) $filterOverrides), @js($allCategories->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->values()), @js($filterAttributes->values()), @js($filterCustomFields->values()))">
        <h2 class="font-semibold mb-1">Per-page filter overrides</h2>
        <p class="text-xs text-ink-700/60 mb-4">By default every shop &amp; category page uses the filters above. Pick a page here to give it its own set of filters — which facets show <em>and</em> which categories are listed. Pages you don't override keep the global default.</p>

        <label class="label">Page</label>
        <select x-model="activeKey" class="input max-w-md">
            <option value="">— Choose a page to customise —</option>
            <template x-for="p in pages" :key="p.key">
                <option :value="p.key" x-text="p.label + (isOn(p.key) ? '   ●' : '')"></option>
            </template>
        </select>
        <p class="text-xs text-ink-700/50 mt-1">A ● marks pages that already have a custom filter set.</p>

        <template x-if="activeKey">
            <div class="mt-4 border-t border-gold-100 pt-4">
                <label class="flex items-center gap-2 font-medium">
                    <input type="checkbox" x-model="cur().enabled">
                    Give this page its own filters (otherwise it uses the global default above)
                </label>

                <div x-show="cur().enabled" x-cloak class="mt-4 space-y-4">
                    <div>
                        <label class="label">Facets to show on this page</label>
                        <div class="grid sm:grid-cols-3 gap-2 text-sm">
                            <label class="flex items-center gap-2"><input type="checkbox" x-model="cur().category"> Category</label>
                            <label class="flex items-center gap-2"><input type="checkbox" x-model="cur().colors"> Colours</label>
                            <label class="flex items-center gap-2"><input type="checkbox" x-model="cur().price"> Price ranges</label>
                            <label class="flex items-center gap-2"><input type="checkbox" x-model="cur().in_stock"> In-stock</label>
                            <label class="flex items-center gap-2"><input type="checkbox" x-model="cur().on_sale"> On-sale</label>
                            <label class="flex items-center gap-2"><input type="checkbox" x-model="cur().tags"> Tags</label>
                        </div>
                    </div>

                    <div x-show="cur().category">
                        <label class="label">Categories listed in the "Category" filter</label>
                        <p class="text-xs text-ink-700/50 mb-1">Leave all unchecked to list every active category.</p>
                        <div class="grid sm:grid-cols-3 gap-2 text-sm">
                            <template x-for="c in categories" :key="c.id">
                                <label class="flex items-center gap-2"><input type="checkbox" :value="c.id" x-model="cur().categories"> <span x-text="c.name"></span></label>
                            </template>
                        </div>
                    </div>

                    <div x-show="attributes.length">
                        <label class="label">Variation attributes to show</label>
                        <div class="grid sm:grid-cols-3 gap-2 text-sm">
                            <template x-for="a in attributes" :key="a">
                                <label class="flex items-center gap-2"><input type="checkbox" :value="a" x-model="cur().attributes"> <span x-text="a"></span></label>
                            </template>
                        </div>
                    </div>

                    <div x-show="customFields.length">
                        <label class="label">Custom fields to show</label>
                        <div class="grid sm:grid-cols-3 gap-2 text-sm">
                            <template x-for="f in customFields" :key="f">
                                <label class="flex items-center gap-2"><input type="checkbox" :value="f" x-model="cur().custom_fields"> <span x-text="f"></span></label>
                            </template>
                        </div>
                    </div>

                    <p class="text-xs text-ink-700/50">Price ranges are inherited from the global default.</p>
                </div>
            </div>
        </template>

        <input type="hidden" name="filter_overrides_json" :value="JSON.stringify(payload())">
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('filterOverrides', (pages, saved, categories, attributes, customFields) => ({
                pages,
                categories,
                attributes,
                customFields,
                data: saved && typeof saved === 'object' ? saved : {},
                activeKey: '',
                blank() {
                    return { enabled: false, category: true, colors: true, price: true, in_stock: true, on_sale: true, tags: false, categories: [], attributes: [], custom_fields: [] };
                },
                cur() {
                    // Must return the SAME stored object so x-model mutations persist.
                    if (!this.data[this.activeKey]) {
                        this.data[this.activeKey] = this.blank();
                    } else {
                        // Backfill any keys missing on legacy saved entries (once).
                        const b = this.blank();
                        for (const k in b) {
                            if (!(k in this.data[this.activeKey])) this.data[this.activeKey][k] = b[k];
                        }
                    }
                    return this.data[this.activeKey];
                },
                isOn(key) {
                    return !!(this.data[key] && this.data[key].enabled);
                },
                payload() {
                    const out = {};
                    for (const k in this.data) {
                        if (this.data[k] && this.data[k].enabled) out[k] = this.data[k];
                    }
                    return out;
                },
            }));

            // Ordered category picker (Highlighted categories + Category scroller).
            Alpine.data('catPicker', (all, initialIds) => ({
                all,
                selected: (initialIds || []).map((id) => all.find((c) => c.id === id)).filter(Boolean),
                addId: '',
                get available() { return this.all.filter((c) => !this.selected.some((s) => s.id === c.id)); },
                add() {
                    const id = parseInt(this.addId, 10);
                    const c = this.all.find((x) => x.id === id);
                    if (c && !this.selected.some((s) => s.id === id)) this.selected.push(c);
                    this.addId = '';
                },
                remove(i) { this.selected.splice(i, 1); },
                move(i, d) {
                    const j = i + d;
                    if (j < 0 || j >= this.selected.length) return;
                    const t = this.selected[i]; this.selected[i] = this.selected[j]; this.selected[j] = t;
                },
            }));
        });
    </script>

    <!-- Marketing -->
    <div class="card p-6" x-show="tab==='branding'">
        <h2 class="font-semibold mb-4">Marketing &amp; tracking</h2>
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="label">Meta Pixel ID</label><input name="meta_pixel_id" value="{{ $theme['meta_pixel_id'] }}" class="input" placeholder="123456789012345"></div>
            <div><label class="label">WhatsApp number (with country code)</label><input name="whatsapp_number" value="{{ $theme['whatsapp_number'] }}" class="input" placeholder="8801XXXXXXXXX"></div>
        </div>
        <p class="text-xs text-ink-700/50 mt-2">The server-side Conversions API (token + enable) is configured under <strong>Meta Integration → Settings</strong> and stored encrypted in the database. Pixel fires PageView, ViewContent, AddToCart, InitiateCheckout &amp; Purchase automatically, deduplicated with the server events.</p>
    </div>

    <!-- Conversion features -->
    <div class="card p-6" x-show="tab==='homepage'">
        <h2 class="font-semibold mb-4">Conversion features</h2>
        <div class="grid sm:grid-cols-2 gap-3 text-sm">
            @php $toggles = [
                'free_shipping_bar' => 'Free-shipping progress bar',
                'sticky_buy_bar' => 'Sticky mobile buy-bar',
                'urgency_low_stock' => 'Low-stock urgency badges',
                'show_reviews' => 'Product reviews & ratings',
                'show_recently_viewed' => 'Recently viewed products',
                'show_frequently_bought' => 'Frequently bought together',
                'exit_intent' => 'Exit-intent discount popup',
            ]; @endphp
            @foreach($toggles as $key => $label)
                <label class="flex items-center gap-2 rounded-lg border border-ink-100 px-3 py-2.5">
                    <input type="checkbox" name="{{ $key }}" value="1" @checked($theme[$key])> {{ $label }}
                </label>
            @endforeach
        </div>
        <div class="mt-4 max-w-xs">
            <label class="label">Low-stock threshold</label>
            <input name="low_stock_threshold" type="number" value="{{ $theme['low_stock_threshold'] }}" class="input">
        </div>
    </div>

    {{-- Footer --}}
    <div class="card p-6" x-show="tab==='branding'">
        <h2 class="font-semibold mb-1">Footer</h2>
        <p class="text-xs text-ink-700/60 mb-4">The Shop / Help / Contact columns fill automatically from your categories &amp; store details. Customise the rest here.</p>
        <div class="space-y-4">
            <div>
                <label class="label">Footer brand text</label>
                <input name="footer_brand" value="{{ $theme['footer_brand'] ?? '' }}" class="input" placeholder="{{ \App\Models\Setting::get('store_name', config('store.name')) }}">
                <p class="text-xs text-ink-700/50 mt-1">The big heading in the footer. Leave empty to use your store name (set in <a href="{{ route('admin.settings') }}" class="text-gold-700 underline">Settings</a>).</p>
            </div>
            <div>
                <label class="label">About text</label>
                <textarea name="footer_about" rows="2" class="input" placeholder="Handpicked jewelry, delivered across Bangladesh…">{{ $theme['footer_about'] ?? '' }}</textarea>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div><label class="label">Facebook page URL</label><input name="footer_facebook" value="{{ $theme['footer_facebook'] ?? '' }}" class="input" placeholder="https://facebook.com/yourpage"></div>
                <div><label class="label">Instagram URL</label><input name="footer_instagram" value="{{ $theme['footer_instagram'] ?? '' }}" class="input" placeholder="https://instagram.com/yourpage"></div>
            </div>
            <div>
                <label class="label">Copyright line</label>
                <input name="footer_copyright" value="{{ $theme['footer_copyright'] ?? '' }}" class="input" placeholder="© {{ date('Y') }} {{ config('store.name') }}. All rights reserved.">
            </div>
        </div>
    </div>

    {{-- Trust badges --}}
    <div class="card p-6" x-show="tab==='branding'" x-data="{ badges: @js(array_values($theme['trust_badges'] ?? config('theme.defaults.trust_badges', []))) }">
        <h2 class="font-semibold mb-1">Trust badges</h2>
        <p class="text-xs text-ink-700/60 mb-4">The reassurance strip shown on product &amp; checkout pages. Add, remove or reorder freely — each badge has an icon (emoji), a title and an optional line of text.</p>
        <div class="space-y-3">
            <template x-for="(b, i) in badges" :key="i">
                <div class="flex gap-2 items-start">
                    <input :name="`trust_badges[${i}][icon]`" x-model="b.icon" class="input w-16 text-center" placeholder="💵" maxlength="4">
                    <input :name="`trust_badges[${i}][title]`" x-model="b.title" class="input flex-1" placeholder="Title (e.g. Cash on delivery)">
                    <input :name="`trust_badges[${i}][text]`" x-model="b.text" class="input flex-1" placeholder="Subtext (optional)">
                    <button type="button" @click="badges.splice(i, 1)" class="text-red-500 px-2 text-xl leading-none" title="Remove">&times;</button>
                </div>
            </template>
        </div>
        <button type="button" @click="badges.push({icon:'✓', title:'', text:''})" class="btn-outline mt-3 text-sm">+ Add badge</button>
        <p class="text-xs text-ink-700/50 mt-2">Tip: 3–4 badges look best. Use emojis for icons (🔒 🚚 ↩️ ⭐ 💎 ✨).</p>
    </div>

    <div class="flex justify-end">
        <button class="btn-primary">Save appearance</button>
    </div>

    {{-- Floating save button — always reachable from anywhere on the page --}}
    <button type="submit"
        class="btn-primary fixed bottom-6 right-6 z-50 shadow-lg shadow-black/20 flex items-center gap-2 rounded-full px-5 py-3">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        Save
    </button>
</form>
@endsection
