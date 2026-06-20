<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (! function_exists('money')) {
    /** Format a value as store currency, e.g. ৳1,250. */
    function money($amount): string
    {
        return config('store.currency_symbol').number_format((float) $amount, 0);
    }
}

if (! function_exists('theme')) {
    /**
     * Read a theme/appearance setting, falling back to config defaults.
     * theme() returns the full merged array; theme('key') returns one value.
     */
    function theme(?string $key = null, $default = null)
    {
        $saved = Setting::get('theme', []);
        $merged = array_merge(config('theme.defaults', []), is_array($saved) ? $saved : []);

        if ($key === null) {
            return $merged;
        }
        return $merged[$key] ?? $default;
    }
}

if (! function_exists('theme_asset')) {
    /** Resolve a stored theme asset path (logo/favicon) to a URL, or null. */
    function theme_asset(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }
        return Str::startsWith($path, ['http://', 'https://'])
            ? $path
            : Storage::disk('public')->url($path);
    }
}

if (! function_exists('home_content')) {
    /**
     * Read an editable homepage-content value, falling back to config defaults.
     * home_content() returns the full merged array; home_content('key') one value.
     */
    function home_content(?string $key = null, $default = null)
    {
        $saved = Setting::get('home_content', []);
        $merged = array_merge(config('home.defaults', []), is_array($saved) ? $saved : []);

        if ($key === null) {
            return $merged;
        }
        // Treat empty strings as "use default" so cleared fields fall back gracefully.
        $value = $merged[$key] ?? null;

        return ($value === null || $value === '') ? ($default ?? config('home.defaults.'.$key)) : $value;
    }
}

if (! function_exists('home_content_heading')) {
    /**
     * Render the hero heading with the configured "highlight" phrase wrapped
     * in an accent <span>. Returns safe HTML (caller uses {!! !!}).
     */
    function home_content_heading(string $highlightClass = 'text-gold-600'): string
    {
        $heading = (string) home_content('hero_heading');
        $highlight = trim((string) home_content('hero_highlight'));
        $safe = e($heading);

        if ($highlight !== '' && Str::contains($heading, $highlight)) {
            $safe = str_replace(
                e($highlight),
                '<span class="'.e($highlightClass).'">'.e($highlight).'</span>',
                $safe
            );
        }

        return $safe;
    }
}

if (! function_exists('site_menu')) {
    /**
     * Resolved storefront navigation menu (max 2 levels).
     * Reads the admin-built menu from settings; falls back to a sensible
     * default built from active top-level categories when none is configured.
     *
     * @return array<int, array{label:string, url:string, new_tab:bool, children:array}>
     */
    function site_menu(): array
    {
        $stored = Setting::get('menu', null);

        // Category lookup once (id => model) for URL resolution.
        $cats = \App\Models\Category::query()->get()->keyBy('id');

        $resolve = function (array $item) use (&$resolve, $cats) {
            $type = $item['type'] ?? 'link';
            $label = trim((string) ($item['label'] ?? ''));
            $url = null;

            if ($type === 'category') {
                $cat = $cats->get((int) ($item['value'] ?? 0));
                if (! $cat || ! $cat->is_active) {
                    return null;
                }
                $label = $label !== '' ? $label : $cat->name;
                $url = route('category.show', $cat->slug);
            } else { // link
                $url = (string) ($item['value'] ?? '#');
            }

            if ($label === '') {
                return null;
            }

            $children = [];
            foreach ($item['children'] ?? [] as $child) {
                $resolved = $resolve($child);
                if ($resolved) {
                    $resolved['children'] = []; // only two levels
                    $children[] = $resolved;
                }
            }

            return ['label' => $label, 'url' => $url, 'new_tab' => (bool) ($item['new_tab'] ?? false), 'children' => $children];
        };

        if (is_array($stored) && ! empty($stored)) {
            return collect($stored)->map($resolve)->filter()->values()->all();
        }

        // Default: "Shop All" + active top-level categories with their children.
        $menu = [['label' => 'Shop All', 'url' => route('shop'), 'new_tab' => false, 'children' => []]];
        foreach ($cats->whereNull('parent_id')->where('is_active', true)->sortBy('position') as $cat) {
            $children = $cats->where('parent_id', $cat->id)->where('is_active', true)->sortBy('position')
                ->map(fn ($c) => ['label' => $c->name, 'url' => route('category.show', $c->slug), 'new_tab' => false, 'children' => []])
                ->values()->all();
            $menu[] = ['label' => $cat->name, 'url' => route('category.show', $cat->slug), 'new_tab' => false, 'children' => $children];
        }

        return $menu;
    }
}

if (! function_exists('meta_pixel_id')) {
    /** Pixel ID from Appearance settings, falling back to .env. */
    function meta_pixel_id(): ?string
    {
        return theme('meta_pixel_id') ?: config('meta.pixel_id');
    }
}
