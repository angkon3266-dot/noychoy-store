<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AppearanceController extends Controller
{
    public function index(\App\Services\StorefrontFilters $filters)
    {
        return view('admin.appearance', [
            'theme' => theme(),
            'home' => home_content(),
            'homeTemplates' => config('theme.homepage_templates'),
            'productTemplates' => config('theme.product_templates'),
            'allCategories' => \App\Models\Category::orderBy('name')->get(['id', 'name']),
            'filterConfig' => $filters->config(),
            'filterAttributes' => $filters->discoverAttributes(),
            'filterCustomFields' => $filters->discoverCustomFields(),
            'filterOverrides' => $filters->overrides(),
            'overridablePages' => $filters->overridablePages(),
            'discoverTiles' => Setting::get('discover_tiles', []),
        ]);
    }

    public function update(Request $request, \App\Services\ImageOptimizer $optimizer)
    {
        $data = $request->validate([
            'primary' => ['nullable', 'string', 'max:9'],
            'accent' => ['nullable', 'string', 'max:9'],
            'background' => ['nullable', 'string', 'max:9'],
            'text' => ['nullable', 'string', 'max:9'],
            'font_heading' => ['nullable', 'string', 'max:60'],
            'font_heading_src' => ['nullable', 'in:google,custom'],
            'font_body' => ['nullable', 'string', 'max:60'],
            'font_body_src' => ['nullable', 'in:google,custom'],
            // Validate by filename extension, not MIME: fileinfo often reports
            // woff2/otf as application/octet-stream, which made `mimes` reject them
            // and silently revert the whole Appearance save (font "lost").
            'font_heading_file' => ['nullable', 'file', 'extensions:woff,woff2,ttf,otf', 'max:8192'],
            'font_body_file' => ['nullable', 'file', 'extensions:woff,woff2,ttf,otf', 'max:8192'],
            'footer_brand' => ['nullable', 'string', 'max:60'],
            'footer_about' => ['nullable', 'string', 'max:300'],
            'footer_facebook' => ['nullable', 'string', 'max:200'],
            'footer_instagram' => ['nullable', 'string', 'max:200'],
            'footer_copyright' => ['nullable', 'string', 'max:200'],
            'footer_show_trust' => ['nullable', 'boolean'],
            'footer_category_ids' => ['nullable', 'array'],
            'footer_category_ids.*' => ['integer'],
            'homepage_template' => ['required', 'string', 'in:'.implode(',', array_keys(config('theme.homepage_templates')))],
            'product_template' => ['required', 'string', 'in:'.implode(',', array_keys(config('theme.product_templates')))],
            'announcement_enabled' => ['nullable', 'boolean'],
            'announcement_bg' => ['nullable', 'string', 'max:9'],
            'announcement_color' => ['nullable', 'string', 'max:9'],
            'announcement_messages' => ['nullable', 'string'],
            'announcement_link' => ['nullable', 'string', 'max:255'],
            'announcement_speed' => ['nullable', 'integer', 'min:2', 'max:30'],
            'meta_pixel_id' => ['nullable', 'string', 'max:40'],
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'free_shipping_bar' => ['nullable', 'boolean'],
            'show_recently_viewed' => ['nullable', 'boolean'],
            'show_reviews' => ['nullable', 'boolean'],
            'urgency_low_stock' => ['nullable', 'boolean'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sticky_buy_bar' => ['nullable', 'boolean'],
            'exit_intent' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'logo_mobile' => ['nullable', 'image', 'max:2048'],
            'logo_align' => ['nullable', 'in:left,center,right'],
            'header_center_image' => ['nullable', 'image', 'max:2048'],
            'header_center_link' => ['nullable', 'string', 'max:255'],
            'favicon' => ['nullable', 'image', 'max:512'],
            'logo_height_desktop' => ['nullable', 'integer', 'min:16', 'max:120'],
            'logo_height_mobile' => ['nullable', 'integer', 'min:16', 'max:100'],
            'header_center_height' => ['nullable', 'integer', 'min:16', 'max:100'],
            'cbar_enabled' => ['nullable', 'boolean'],
            'cbar_text' => ['nullable', 'string', 'max:200'],
            'cbar_code' => ['nullable', 'string', 'max:40'],
            'cbar_link' => ['nullable', 'string', 'max:255'],
            'cbar_link_label' => ['nullable', 'string', 'max:40'],
            'cbar_bg' => ['nullable', 'string', 'max:9'],
            'cbar_color' => ['nullable', 'string', 'max:9'],
            'menu_icon' => ['nullable', 'image', 'max:1024'],
            'menu_icon_rotation' => ['nullable', 'integer', 'min:0', 'max:360'],
            'menu_icon_height' => ['nullable', 'integer', 'min:16', 'max:80'],
            // Editable trust strip
            'trust_badges' => ['nullable', 'array'],
            'trust_badges.*.icon' => ['nullable', 'string', 'max:8'],
            'trust_badges.*.title' => ['nullable', 'string', 'max:40'],
            'trust_badges.*.text' => ['nullable', 'string', 'max:60'],
            // Editable homepage content
            'home' => ['nullable', 'array'],
            'home.*' => ['nullable', 'string', 'max:500'],
            'hero_image' => ['nullable', 'image', 'max:4096'],
            'promise_image' => ['nullable', 'image', 'max:4096'],

            // Storefront homepage builder
            'feature_strip' => ['nullable', 'array'],
            'feature_strip.*.icon' => ['nullable', 'string', 'max:8'],
            'feature_strip.*.title' => ['nullable', 'string', 'max:60'],
            'highlight_category_ids' => ['nullable', 'array'],
            'highlight_category_ids.*' => ['integer'],
            'category_scroller_ids' => ['nullable', 'array'],
            'category_scroller_ids.*' => ['integer'],
            'hero_slides' => ['nullable', 'array'],
            'hero_slide_images' => ['nullable', 'array'],
            'hero_slide_images.*' => ['nullable', 'image', 'max:4096'],
            'hero_slide_urls' => ['nullable', 'array'],
            'hero_slide_urls.*' => ['nullable', 'string', 'max:500'],

            // Section builder
            'home_sections_json' => ['nullable', 'string'],
            'block_image' => ['nullable', 'array'],
            'block_image.*.*' => ['nullable', 'image', 'max:4096'],
            'block_banner' => ['nullable', 'array'],
            'block_banner.*' => ['nullable', 'image', 'max:4096'],
            'block_cta' => ['nullable', 'array'],
            'block_cta.*' => ['nullable', 'image', 'max:4096'],

            // Floating contact buttons
            'messenger_url' => ['nullable', 'string', 'max:255'],

            // Discover page tiles
            'discover_tiles_json' => ['nullable', 'string'],
            'discover_image' => ['nullable', 'array'],
            'discover_image.*' => ['nullable', 'image', 'max:2048'],

            // Catalog
            'products_per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'default_sort' => ['nullable', 'in:new,popular,best_selling,price_asc,price_desc,name'],

            // Storefront filters
            'filter_attributes' => ['nullable', 'array'],
            'filter_custom_fields' => ['nullable', 'array'],
            'filter_categories' => ['nullable', 'array'],
            'filter_categories.*' => ['integer'],
            'filter_price_ranges' => ['nullable', 'string'],
        ]);

        $current = theme();

        // Files (images) — upload replaces, or "remove" checkbox clears.
        // Branding art is downscaled + converted to small WebP so pages load fast
        // (important for ad traffic). Caps are ~2× the largest display size.
        // Higher caps + quality for the logo so it stays crisp on hi-dpi screens.
        $brandingMax = ['logo' => 1200, 'logo_mobile' => 800, 'header_center_image' => 800, 'menu_icon' => 200, 'favicon' => 128];
        foreach (['logo', 'logo_mobile', 'header_center_image', 'menu_icon', 'favicon'] as $file) {
            $urlPick = trim((string) $request->input($file.'_url', ''));
            if ($request->hasFile($file)) {
                if (! empty($current[$file]) && ! str_starts_with($current[$file], 'http')) {
                    Storage::disk('public')->delete($current[$file]);
                }
                $current[$file] = $optimizer->storeWebp($request->file($file), 'branding', $brandingMax[$file], 92);
            } elseif ($urlPick !== '') {
                // Media-library pick (reused in place) or a remote URL (imported).
                $path = public_url_to_path($urlPick) ?: $optimizer->storeWebpFromUrl($urlPick, 'branding', $brandingMax[$file], 92);
                if (! empty($current[$file]) && $current[$file] !== $path && ! str_starts_with($current[$file], 'http')) {
                    Storage::disk('public')->delete($current[$file]);
                }
                $current[$file] = $path;
            } elseif ($request->boolean('remove_'.$file) || $request->boolean($file.'_cleared')) {
                if (! empty($current[$file]) && ! str_starts_with($current[$file], 'http')) {
                    Storage::disk('public')->delete($current[$file]);
                }
                $current[$file] = null;
            }
        }

        // Custom font uploads (Blore etc.) → stored on public disk.
        foreach (['font_heading_file', 'font_body_file'] as $fontFile) {
            if ($request->hasFile($fontFile)) {
                if (! empty($current[$fontFile])) {
                    Storage::disk('public')->delete($current[$fontFile]);
                }
                $current[$fontFile] = $request->file($fontFile)->store('fonts', 'public');
            }
        }

        // ---- Editable homepage content (stored separately under 'home_content') ----
        $home = Setting::get('home_content', []);
        $home = is_array($home) ? $home : [];
        foreach (($data['home'] ?? []) as $key => $value) {
            if (array_key_exists($key, config('home.defaults', []))) {
                $home[$key] = is_string($value) ? trim($value) : $value;
            }
        }
        $heroPick = trim((string) $request->input('hero_image_url', ''));
        if ($request->hasFile('hero_image')) {
            if (! empty($home['hero_image']) && ! str_starts_with($home['hero_image'], 'http')) {
                Storage::disk('public')->delete($home['hero_image']);
            }
            $home['hero_image'] = $optimizer->storeWebp($request->file('hero_image'), 'branding', 1600, 85);
        } elseif ($heroPick !== '') {
            $path = public_url_to_path($heroPick) ?: $optimizer->storeWebpFromUrl($heroPick, 'branding', 1600, 85);
            if (! empty($home['hero_image']) && $home['hero_image'] !== $path && ! str_starts_with($home['hero_image'], 'http')) {
                Storage::disk('public')->delete($home['hero_image']);
            }
            $home['hero_image'] = $path;
        } elseif (($request->boolean('remove_hero_image') || $request->boolean('hero_image_cleared')) && ! empty($home['hero_image'])) {
            if (! str_starts_with($home['hero_image'], 'http')) {
                Storage::disk('public')->delete($home['hero_image']);
            }
            $home['hero_image'] = null;
        }

        // "Our promise" band image (same upload/library/clear flow as the hero).
        $promisePick = trim((string) $request->input('promise_image_url', ''));
        if ($request->hasFile('promise_image')) {
            if (! empty($home['promise_image']) && ! str_starts_with($home['promise_image'], 'http')) {
                Storage::disk('public')->delete($home['promise_image']);
            }
            $home['promise_image'] = $optimizer->storeWebp($request->file('promise_image'), 'branding', 1400, 85);
        } elseif ($promisePick !== '') {
            $path = public_url_to_path($promisePick) ?: $optimizer->storeWebpFromUrl($promisePick, 'branding', 1400, 85);
            if (! empty($home['promise_image']) && $home['promise_image'] !== $path && ! str_starts_with($home['promise_image'], 'http')) {
                Storage::disk('public')->delete($home['promise_image']);
            }
            $home['promise_image'] = $path;
        } elseif ($request->boolean('promise_image_cleared') && ! empty($home['promise_image'])) {
            if (! str_starts_with($home['promise_image'], 'http')) {
                Storage::disk('public')->delete($home['promise_image']);
            }
            $home['promise_image'] = null;
        }

        // ---- Storefront homepage builder ----
        // Section toggles
        foreach (['show_feature_strip', 'show_categories', 'show_best_selling', 'show_new_arrivals', 'show_highlights', 'show_promise'] as $t) {
            $home[$t] = $request->boolean('home_'.$t);
        }

        // Feature strip
        if ($request->has('feature_strip')) {
            $home['feature_strip'] = collect($request->input('feature_strip', []))
                ->map(fn ($f) => ['icon' => trim((string) ($f['icon'] ?? '')), 'title' => trim((string) ($f['title'] ?? ''))])
                ->filter(fn ($f) => $f['title'] !== '')->values()->all();
        }

        // Highlighted categories + Category scroller (ordered). The _present marker
        // lets an empty selection clear the list rather than being ignored.
        if ($request->has('highlight_category_ids_present')) {
            $home['highlight_category_ids'] = collect($request->input('highlight_category_ids', []))
                ->map(fn ($i) => (int) $i)->filter()->values()->all();
        }
        if ($request->has('category_scroller_ids_present')) {
            $home['category_scroller_ids'] = collect($request->input('category_scroller_ids', []))
                ->map(fn ($i) => (int) $i)->filter()->values()->all();
        }

        // Hero slides: edit links / remove existing, then append new uploads
        $slides = collect($home['hero_slides'] ?? []);
        $edits = $request->input('hero_slides', []);
        $slides = $slides->reject(fn ($s, $i) => ! empty($edits[$i]['remove']))
            ->map(function ($s, $i) use ($edits) {
                if (isset($edits[$i]['link'])) {
                    $s['link'] = trim((string) $edits[$i]['link']);
                }
                return $s;
            });
        if ($request->hasFile('hero_slide_images')) {
            foreach ($request->file('hero_slide_images') as $file) {
                if ($file && $file->isValid()) {
                    $slides->push(['image' => $file->store('hero', 'public'), 'link' => '']);
                }
            }
        }
        // Media-library picks → new slides (store the disk path, or keep a remote URL).
        foreach ((array) $request->input('hero_slide_urls', []) as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                $slides->push(['image' => public_url_to_path($url) ?: $url, 'link' => '']);
            }
        }
        $home['hero_slides'] = $slides->values()->all();

        // Section builder blocks (JSON) + per-block image uploads
        $blocks = json_decode((string) $request->input('home_sections_json', ''), true);
        if (is_array($blocks)) {
            foreach ((array) $request->file('block_image', []) as $bi => $imgs) {
                foreach ((array) $imgs as $ii => $file) {
                    if ($file && $file->isValid()) {
                        $blocks[$bi]['images'][$ii]['image'] = $file->store('sections', 'public');
                    }
                }
            }
            foreach ((array) $request->file('block_banner', []) as $bi => $file) {
                if ($file && $file->isValid()) {
                    $blocks[$bi]['banner']['image'] = $file->store('sections', 'public');
                }
            }
            foreach ((array) $request->file('block_cta', []) as $bi => $file) {
                if ($file && $file->isValid()) {
                    $blocks[$bi]['cta']['image'] = $file->store('sections', 'public');
                }
            }
            $home['sections'] = $this->normalizeSections($blocks);
        }

        Setting::put('home_content', $home);

        // Booleans (checkboxes)
        foreach (['announcement_enabled', 'free_shipping_bar', 'show_recently_viewed', 'show_reviews', 'show_frequently_bought', 'urgency_low_stock', 'sticky_buy_bar', 'exit_intent', 'show_call_button', 'show_whatsapp_button', 'show_messenger_button', 'cbar_enabled', 'footer_show_trust'] as $bool) {
            $current[$bool] = $request->boolean($bool);
        }

        // Trust strip badges: keep only rows with a title.
        if (array_key_exists('trust_badges', $data)) {
            $current['trust_badges'] = collect($data['trust_badges'] ?? [])
                ->map(fn ($b) => [
                    'icon' => trim((string) ($b['icon'] ?? '')),
                    'title' => trim((string) ($b['title'] ?? '')),
                    'text' => trim((string) ($b['text'] ?? '')),
                ])
                ->filter(fn ($b) => $b['title'] !== '')
                ->values()->all();
        }

        // Announcement messages: one per line -> array
        if (array_key_exists('announcement_messages', $data)) {
            $current['announcement_messages'] = collect(preg_split('/\r\n|\r|\n/', (string) $data['announcement_messages']))
                ->map(fn ($l) => trim($l))->filter()->values()->all();
            unset($data['announcement_messages']);
        }

        // Scalars
        foreach (['primary', 'accent', 'background', 'text', 'font_heading', 'font_heading_src', 'font_body', 'font_body_src', 'homepage_template', 'product_template', 'announcement_bg', 'announcement_color', 'announcement_link', 'announcement_speed', 'meta_pixel_id', 'whatsapp_number', 'messenger_url', 'low_stock_threshold', 'logo_align', 'logo_height_desktop', 'logo_height_mobile', 'header_center_height', 'header_center_link', 'menu_icon_rotation', 'menu_icon_height', 'products_per_page', 'default_sort', 'cbar_text', 'cbar_code', 'cbar_link', 'cbar_link_label', 'cbar_bg', 'cbar_color', 'footer_brand', 'footer_about', 'footer_facebook', 'footer_instagram', 'footer_copyright'] as $key) {
            if (array_key_exists($key, $data)) {
                $current[$key] = $data[$key];
            }
        }

        // Footer "Shop" categories (ordered). The _present marker lets an empty
        // selection clear the list (fall back to auto) rather than being ignored.
        if ($request->has('footer_category_ids_present')) {
            $current['footer_category_ids'] = collect($request->input('footer_category_ids', []))
                ->map(fn ($i) => (int) $i)->filter()->values()->all();
        }

        Setting::put('theme', $current);

        // ---- Storefront filters ----
        $sf = Setting::get('storefront_filters', []);
        $sf = is_array($sf) ? $sf : [];
        $sf['attributes'] = array_values(array_filter((array) $request->input('filter_attributes', [])));
        $sf['custom_fields'] = array_values(array_filter((array) $request->input('filter_custom_fields', [])));
        // Which categories appear as a filter option in the sidebar (admin choice).
        $sf['categories'] = array_values(array_filter(array_map('intval', (array) $request->input('filter_categories', []))));
        $sf['category'] = $request->boolean('filter_category');
        $sf['colors'] = $request->boolean('filter_colors');
        $sf['tags'] = $request->boolean('filter_tags');
        $sf['price'] = $request->boolean('filter_price');
        $sf['in_stock'] = $request->boolean('filter_in_stock');
        $sf['on_sale'] = $request->boolean('filter_on_sale');
        $ranges = collect(preg_split('/\r\n|\r|\n/', (string) $request->input('filter_price_ranges', '')))
            ->map(fn ($l) => array_map('trim', explode('-', $l)))
            ->filter(fn ($r) => count($r) === 2 && is_numeric($r[0]) && is_numeric($r[1]))
            ->map(fn ($r) => [(float) $r[0], (float) $r[1]])->values()->all();
        if ($ranges) {
            $sf['price_ranges'] = $ranges;
        }
        Setting::put('storefront_filters', $sf);

        // ---- Per-page filter overrides (global default + per-page overrides) ----
        // The Appearance UI submits a JSON map { "<pageKey>": { enabled, ...cfg } }.
        // Only enabled pages are kept; everything else inherits the global default.
        $overrides = json_decode((string) $request->input('filter_overrides_json', ''), true);
        if (is_array($overrides)) {
            $clean = [];
            foreach ($overrides as $pageKey => $ov) {
                if (! is_array($ov) || empty($ov['enabled'])) {
                    continue;
                }
                $clean[$pageKey] = [
                    'enabled' => true,
                    'categories' => array_values(array_filter(array_map('intval', (array) ($ov['categories'] ?? [])))),
                    'attributes' => array_values(array_filter((array) ($ov['attributes'] ?? []))),
                    'custom_fields' => array_values(array_filter((array) ($ov['custom_fields'] ?? []))),
                    'category' => ! empty($ov['category']),
                    'colors' => ! empty($ov['colors']),
                    'tags' => ! empty($ov['tags']),
                    'price' => ! empty($ov['price']),
                    'in_stock' => ! empty($ov['in_stock']),
                    'on_sale' => ! empty($ov['on_sale']),
                ];
            }
            Setting::put('storefront_filter_overrides', $clean);
        }

        // ---- Discover page tiles (image + name + link) ----
        $tiles = json_decode((string) $request->input('discover_tiles_json', ''), true);
        if (is_array($tiles)) {
            foreach ((array) $request->file('discover_image', []) as $i => $file) {
                if ($file && $file->isValid()) {
                    $tiles[$i]['image'] = $optimizer->storeWebp($file, 'discover', 800, 85);
                }
            }
            $tiles = collect($tiles)
                ->map(fn ($t) => [
                    'image' => trim((string) ($t['image'] ?? '')),
                    'name' => trim((string) ($t['name'] ?? '')),
                    'link' => trim((string) ($t['link'] ?? '')),
                ])
                ->filter(fn ($t) => $t['image'] !== '')
                ->values()->all();
            Setting::put('discover_tiles', $tiles);
        }

        // Homepage content/sections may have changed — rebuild its cache.
        \Illuminate\Support\Facades\Cache::forget(\App\Http\Controllers\Shop\HomeController::CACHE_KEY);

        return back()->with('success', 'Appearance updated.');
    }

    /** Sanitise builder blocks to a known shape before storing. */
    protected function normalizeSections(array $blocks): array
    {
        $types = ['banner', 'product_carousel', 'banner_carousel', 'cta_banner', 'video', 'richtext'];

        return collect($blocks)->map(function ($b) use ($types) {
            $type = in_array($b['type'] ?? '', $types, true) ? $b['type'] : null;
            if (! $type) {
                return null;
            }
            $out = [
                'type' => $type,
                'enabled' => (bool) ($b['enabled'] ?? true),
                'title' => trim((string) ($b['title'] ?? '')),
            ];
            if ($type === 'banner') {
                $out['layout'] = in_array($b['layout'] ?? 'single', ['single', 'dual', 'grid'], true) ? $b['layout'] : 'single';
                $out['images'] = collect($b['images'] ?? [])
                    ->map(fn ($i) => ['image' => trim((string) ($i['image'] ?? '')), 'link' => trim((string) ($i['link'] ?? ''))])
                    ->filter(fn ($i) => $i['image'] !== '')->values()->all();
            }
            if (in_array($type, ['product_carousel', 'banner_carousel'], true)) {
                $out['source'] = in_array($b['source'] ?? 'new', ['new', 'best', 'featured', 'category'], true) ? $b['source'] : 'new';
                $out['category_id'] = ((int) ($b['category_id'] ?? 0)) ?: null;
                $out['limit'] = max(1, min(20, (int) ($b['limit'] ?? 10)));
                $out['view_all_link'] = trim((string) ($b['view_all_link'] ?? ''));
            }
            if ($type === 'banner_carousel') {
                $out['banner'] = [
                    'image' => trim((string) ($b['banner']['image'] ?? '')),
                    'link' => trim((string) ($b['banner']['link'] ?? '')),
                ];
            }
            if ($type === 'cta_banner') {
                $out['cta'] = [
                    'image' => trim((string) ($b['cta']['image'] ?? '')),
                    'eyebrow' => trim((string) ($b['cta']['eyebrow'] ?? '')),
                    'heading' => trim((string) ($b['cta']['heading'] ?? '')),
                    'subheading' => trim((string) ($b['cta']['subheading'] ?? '')),
                    'button_text' => trim((string) ($b['cta']['button_text'] ?? '')),
                    'button_link' => trim((string) ($b['cta']['button_link'] ?? '')),
                    'align' => in_array($b['cta']['align'] ?? 'center', ['left', 'center', 'right'], true) ? $b['cta']['align'] : 'center',
                    'height' => in_array($b['cta']['height'] ?? 'md', ['sm', 'md', 'lg'], true) ? $b['cta']['height'] : 'md',
                ];
            }
            if ($type === 'video') {
                $out['videos'] = collect($b['videos'] ?? [])
                    ->map(fn ($v) => ['title' => trim((string) ($v['title'] ?? '')), 'url' => trim((string) ($v['url'] ?? ''))])
                    ->filter(fn ($v) => $v['url'] !== '')->values()->all();
            }
            if ($type === 'richtext') {
                $out['html'] = (string) ($b['html'] ?? '');
            }

            return $out;
        })->filter()->values()->all();
    }
}
