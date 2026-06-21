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
                    <img src="{{ $logo }}" class="h-12 mb-2 bg-ink-900 rounded p-1" alt="logo">
                @endif
                <input type="file" name="logo" accept="image/*" class="input text-sm">
                <p class="text-xs text-ink-700/50 mt-1">PNG with transparent background recommended. Leave empty to use the text logo.</p>
            </div>
            <div>
                <label class="label">Favicon</label>
                @if($fav = theme_asset($theme['favicon']))<img src="{{ $fav }}" class="h-8 mb-2" alt="favicon">@endif
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

    <div class="flex justify-end">
        <button class="btn-primary">Save appearance</button>
    </div>
</form>
@endsection
