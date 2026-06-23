@extends('layouts.admin')
@section('title', 'Appearance')
@section('heading', 'Appearance & Theme')

@section('content')
<form action="{{ route('admin.appearance.update') }}" method="POST" enctype="multipart/form-data" class="space-y-6 max-w-4xl">
    @csrf

    <!-- Branding -->
    <div class="card p-6">
        <h2 class="font-semibold mb-4">Branding</h2>
        <div class="grid sm:grid-cols-2 gap-6">
            <div>
                <label class="label">Logo</label>
                @if($logo = theme_asset($theme['logo']))
                    <div class="flex items-center gap-3 mb-2">
                        <img src="{{ $logo }}" class="h-12 bg-ink-900 rounded p-1" alt="logo">
                        <label class="flex items-center gap-1.5 text-xs text-red-600"><input type="checkbox" name="remove_logo" value="1"> Remove</label>
                    </div>
                @endif
                <input type="file" name="logo" accept="image/*" class="input text-sm">
                <p class="text-xs text-ink-700/50 mt-1">Desktop logo. PNG with transparent background recommended. Leave empty to use the text logo.</p>

                <label class="label mt-3">Mobile logo</label>
                @if($logoM = theme_asset($theme['logo_mobile'] ?? null))
                    <div class="flex items-center gap-3 mb-2">
                        <img src="{{ $logoM }}" class="h-10 bg-ink-900 rounded p-1" alt="mobile logo">
                        <label class="flex items-center gap-1.5 text-xs text-red-600"><input type="checkbox" name="remove_logo_mobile" value="1"> Remove</label>
                    </div>
                @endif
                <input type="file" name="logo_mobile" accept="image/*" class="input text-sm">
                <p class="text-xs text-ink-700/50 mt-1">Optional. Shown on mobile only (left-aligned). Falls back to the desktop logo.</p>

                <div class="grid grid-cols-2 gap-3 mt-3">
                    <div><label class="label text-xs">Logo height — desktop (px)</label><input type="number" name="logo_height_desktop" value="{{ $theme['logo_height_desktop'] ?? 40 }}" min="16" max="120" class="input text-sm"></div>
                    <div><label class="label text-xs">Logo height — mobile (px)</label><input type="number" name="logo_height_mobile" value="{{ $theme['logo_height_mobile'] ?? 32 }}" min="16" max="100" class="input text-sm"></div>
                </div>

                <label class="label mt-4">Mobile center image (optional)</label>
                @if($hc = theme_asset($theme['header_center_image'] ?? null))
                    <div class="flex items-center gap-3 mb-2">
                        <img src="{{ $hc }}" class="h-10 bg-ink-900 rounded p-1" alt="center">
                        <label class="flex items-center gap-1.5 text-xs text-red-600"><input type="checkbox" name="remove_header_center_image" value="1"> Remove</label>
                    </div>
                @endif
                <input type="file" name="header_center_image" accept="image/*" class="input text-sm">
                <div class="grid grid-cols-2 gap-3 mt-2">
                    <div><label class="label text-xs">Center image link</label><input name="header_center_link" value="{{ $theme['header_center_link'] ?? '' }}" class="input text-sm" placeholder="(optional)"></div>
                    <div><label class="label text-xs">Center image height (px)</label><input type="number" name="header_center_height" value="{{ $theme['header_center_height'] ?? 32 }}" min="16" max="100" class="input text-sm"></div>
                </div>
                <p class="text-xs text-ink-700/50 mt-1">Shows centered in the mobile header (e.g. a badge or campaign mark).</p>

                <label class="label mt-4">Mobile menu icon</label>
                @if($mi = theme_asset($theme['menu_icon'] ?? null))
                    <div class="flex items-center gap-3 mb-2">
                        <img src="{{ $mi }}" class="h-9 bg-ink-900 rounded p-1" alt="menu icon">
                        <label class="flex items-center gap-1.5 text-xs text-red-600"><input type="checkbox" name="remove_menu_icon" value="1"> Remove</label>
                    </div>
                @endif
                <input type="file" name="menu_icon" accept="image/*" class="input text-sm">
                <div class="grid grid-cols-2 gap-3 mt-2">
                    <div><label class="label text-xs">Rotation when menu opens (°)</label><input type="number" name="menu_icon_rotation" value="{{ $theme['menu_icon_rotation'] ?? 45 }}" min="0" max="360" class="input text-sm"></div>
                    <div><label class="label text-xs">Icon size (px)</label><input type="number" name="menu_icon_height" value="{{ $theme['menu_icon_height'] ?? 28 }}" min="16" max="80" class="input text-sm"></div>
                </div>
                <p class="text-xs text-ink-700/50 mt-1">Used as the mobile menu toggle (e.g. your "M" mark). Tapping opens the menu and rotates the icon by this angle over 300ms; tapping again closes &amp; rotates back. Mobile only.</p>
            </div>
            <div>
                <label class="label">Favicon</label>
                @if($fav = theme_asset($theme['favicon']))
                    <div class="flex items-center gap-3 mb-2">
                        <img src="{{ $fav }}" class="h-8" alt="favicon">
                        <label class="flex items-center gap-1.5 text-xs text-red-600"><input type="checkbox" name="remove_favicon" value="1"> Remove</label>
                    </div>
                @endif
                <input type="file" name="favicon" accept="image/*" class="input text-sm">
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
    <div class="card p-6">
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
    <div class="card p-6">
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
    <div class="card p-6">
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
    <div class="card p-6">
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
            <label class="label">Hero background image (optional)</label>
            @if($heroImg = theme_asset($home['hero_image']))
                <div class="flex items-center gap-3 mb-2">
                    <img src="{{ $heroImg }}" class="h-16 w-28 object-cover rounded border border-ink-100" alt="hero">
                    <label class="flex items-center gap-1.5 text-xs text-red-600"><input type="checkbox" name="remove_hero_image" value="1"> Remove current image</label>
                </div>
            @endif
            <input type="file" name="hero_image" accept="image/*" class="input text-sm">
            <p class="text-xs text-ink-700/50 mt-1">Used as the hero background on Aurelia / Maison and the side image on Lumière. Wide image (e.g. 1600×900) recommended.</p>
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
    </div>

    <!-- Homepage sections (Storefront template) -->
    <div class="card p-6">
        <h2 class="font-semibold mb-1">Homepage sections</h2>
        <p class="text-xs text-ink-700/60 mb-4">Build the storefront homepage — hero slider, feature strip, best sellers, new arrivals, highlighted categories and videos. Toggle each section on/off.</p>

        {{-- Toggles --}}
        <div class="grid sm:grid-cols-3 gap-2 mb-6 text-sm">
            @foreach(['show_feature_strip'=>'Feature strip','show_categories'=>'Category scroller','show_best_selling'=>'Best selling','show_new_arrivals'=>'New arrivals','show_highlights'=>'Highlighted categories','show_videos'=>'Video sections'] as $k => $lbl)
                <label class="flex items-center gap-2"><input type="checkbox" name="home_{{ $k }}" value="1" @checked($home[$k] ?? true)> {{ $lbl }}</label>
            @endforeach
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
        <label class="label">Add slide image(s)</label>
        <input type="file" name="hero_slide_images[]" multiple accept="image/*" class="input text-sm">
        <p class="text-xs text-ink-700/50 mt-1">Wide images work best (e.g. 1920×800). Add several for an auto-rotating slider.</p>

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

        {{-- Highlighted categories --}}
        <h3 class="text-sm font-semibold text-ink-700 mt-6 mb-2">Highlighted categories</h3>
        <p class="text-xs text-ink-700/50 mb-2">Pick categories to feature as large editorial banners.</p>
        @php $hl = collect($home['highlight_category_ids'] ?? [])->map(fn($i)=>(int)$i); @endphp
        <div class="grid sm:grid-cols-3 gap-2 text-sm">
            @foreach($allCategories as $cat)
                <label class="flex items-center gap-2"><input type="checkbox" name="highlight_category_ids[]" value="{{ $cat->id }}" @checked($hl->contains($cat->id))> {{ $cat->name }}</label>
            @endforeach
        </div>

        {{-- Video sections --}}
        <h3 class="text-sm font-semibold text-ink-700 mt-6 mb-2">Video sections</h3>
        <div x-data="{ vids: @js(array_values($home['videos'] ?? [])) }">
            <template x-for="(v, i) in vids" :key="i">
                <div class="flex gap-2 mb-2">
                    <input :name="`home_videos[${i}][title]`" x-model="v.title" class="input w-48" placeholder="Title (optional)">
                    <input :name="`home_videos[${i}][url]`" x-model="v.url" class="input flex-1" placeholder="YouTube link or stored path">
                    <button type="button" @click="vids.splice(i,1)" class="text-red-500 px-2 text-xl leading-none">&times;</button>
                </div>
            </template>
            <button type="button" @click="vids.push({title:'',url:''})" class="btn-outline text-sm">+ Add video link</button>
        </div>
        <div class="mt-3"><label class="label text-xs">Or upload MP4/WebM (max 30 MB each)</label><input type="file" name="home_video_files[]" multiple accept="video/mp4,video/webm" class="input text-sm"></div>
    </div>

    <!-- Section builder -->
    <div class="card p-6" x-data="homeBuilder({ blocks: @js(array_values($home['sections'] ?? [])) })">
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
                            <input x-model="im.image" class="input py-1.5 text-sm flex-1" placeholder="image path or URL">
                            <input type="file" :name="`block_image[${bi}][${ii}]`" accept="image/*" class="text-xs w-40">
                            <input x-model="im.link" class="input py-1.5 text-sm w-40" placeholder="link">
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
                    <input type="file" :name="`block_banner[${bi}]`" accept="image/*" class="text-xs w-40">
                    <input x-model="b.banner.link" class="input py-1.5 text-sm w-40" placeholder="link">
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

    <!-- Floating contact buttons -->
    <div class="card p-6">
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
    <div class="card p-6">
        <h2 class="font-semibold mb-1">Storefront filters</h2>
        <p class="text-xs text-ink-700/60 mb-4">Choose which filters appear in the shop sidebar. Values are pulled automatically from your products. "Color" attributes show colour swatches.</p>

        <div class="grid sm:grid-cols-3 gap-2 text-sm mb-4">
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

    <!-- Marketing -->
    <div class="card p-6">
        <h2 class="font-semibold mb-4">Marketing &amp; tracking</h2>
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="label">Meta Pixel ID</label><input name="meta_pixel_id" value="{{ $theme['meta_pixel_id'] }}" class="input" placeholder="123456789012345"></div>
            <div><label class="label">WhatsApp number (with country code)</label><input name="whatsapp_number" value="{{ $theme['whatsapp_number'] }}" class="input" placeholder="8801XXXXXXXXX"></div>
        </div>
        <p class="text-xs text-ink-700/50 mt-2">Conversions API token is set in the server <code>.env</code> (META_CAPI_TOKEN) for security. Pixel fires PageView, ViewContent, AddToCart, InitiateCheckout &amp; Purchase automatically.</p>
    </div>

    <!-- Conversion features -->
    <div class="card p-6">
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
    <div class="card p-6">
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
    <div class="card p-6" x-data="{ badges: @js(array_values($theme['trust_badges'] ?? config('theme.defaults.trust_badges', []))) }">
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
</form>
@endsection
