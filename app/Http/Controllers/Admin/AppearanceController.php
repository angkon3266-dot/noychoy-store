<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AppearanceController extends Controller
{
    public function index()
    {
        return view('admin.appearance', [
            'theme' => theme(),
            'home' => home_content(),
            'homeTemplates' => config('theme.homepage_templates'),
            'productTemplates' => config('theme.product_templates'),
        ]);
    }

    public function update(Request $request)
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
            'font_heading_file' => ['nullable', 'file', 'mimes:woff,woff2,ttf,otf', 'max:4096'],
            'font_body_file' => ['nullable', 'file', 'mimes:woff,woff2,ttf,otf', 'max:4096'],
            'footer_about' => ['nullable', 'string', 'max:300'],
            'footer_facebook' => ['nullable', 'string', 'max:200'],
            'footer_instagram' => ['nullable', 'string', 'max:200'],
            'footer_copyright' => ['nullable', 'string', 'max:200'],
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
            'favicon' => ['nullable', 'image', 'max:512'],
            // Editable homepage content
            'home' => ['nullable', 'array'],
            'home.*' => ['nullable', 'string', 'max:500'],
            'hero_image' => ['nullable', 'image', 'max:4096'],
        ]);

        $current = theme();

        // Files (images)
        foreach (['logo', 'favicon'] as $file) {
            if ($request->hasFile($file)) {
                if (! empty($current[$file]) && ! str_starts_with($current[$file], 'http')) {
                    Storage::disk('public')->delete($current[$file]);
                }
                $current[$file] = $request->file($file)->store('branding', 'public');
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
        if ($request->hasFile('hero_image')) {
            if (! empty($home['hero_image']) && ! str_starts_with($home['hero_image'], 'http')) {
                Storage::disk('public')->delete($home['hero_image']);
            }
            $home['hero_image'] = $request->file('hero_image')->store('branding', 'public');
        }
        if ($request->boolean('remove_hero_image') && ! empty($home['hero_image'])) {
            if (! str_starts_with($home['hero_image'], 'http')) {
                Storage::disk('public')->delete($home['hero_image']);
            }
            $home['hero_image'] = null;
        }
        Setting::put('home_content', $home);

        // Booleans (checkboxes)
        foreach (['announcement_enabled', 'free_shipping_bar', 'show_recently_viewed', 'show_reviews', 'show_frequently_bought', 'urgency_low_stock', 'sticky_buy_bar', 'exit_intent'] as $bool) {
            $current[$bool] = $request->boolean($bool);
        }

        // Announcement messages: one per line -> array
        if (array_key_exists('announcement_messages', $data)) {
            $current['announcement_messages'] = collect(preg_split('/\r\n|\r|\n/', (string) $data['announcement_messages']))
                ->map(fn ($l) => trim($l))->filter()->values()->all();
            unset($data['announcement_messages']);
        }

        // Scalars
        foreach (['primary', 'accent', 'background', 'text', 'font_heading', 'font_heading_src', 'font_body', 'font_body_src', 'homepage_template', 'product_template', 'announcement_bg', 'announcement_color', 'announcement_link', 'announcement_speed', 'meta_pixel_id', 'whatsapp_number', 'low_stock_threshold', 'footer_about', 'footer_facebook', 'footer_instagram', 'footer_copyright'] as $key) {
            if (array_key_exists($key, $data)) {
                $current[$key] = $data[$key];
            }
        }

        Setting::put('theme', $current);

        return back()->with('success', 'Appearance updated.');
    }
}
