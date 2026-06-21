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

        if (is_array($stored) && ! empty($stored)) {
            return collect($stored)->map(fn ($i) => normalize_menu_item($i))->filter()->values()->all();
        }

        // Default: "Shop All" + active top-level categories as dropdowns.
        $cats = \App\Models\Category::query()->where('is_active', true)->get();
        $menu = [[
            'label' => 'Shop All', 'type' => 'link', 'url' => route('shop'),
            'new_tab' => false, 'badge' => null, 'view_all_mobile' => false, 'children' => [], 'columns' => [],
        ]];
        foreach ($cats->whereNull('parent_id')->sortBy('position') as $cat) {
            $children = $cats->where('parent_id', $cat->id)->sortBy('position')
                ->map(fn ($c) => ['label' => $c->name, 'url' => route('category.show', $c->slug), 'new_tab' => false])
                ->values()->all();
            $menu[] = [
                'label' => $cat->name,
                'type' => $children ? 'dropdown' : 'link',
                'url' => route('category.show', $cat->slug),
                'new_tab' => false, 'badge' => null, 'view_all_mobile' => true,
                'children' => $children, 'columns' => [],
            ];
        }

        return $menu;
    }
}

if (! function_exists('normalize_menu_item')) {
    /** Normalise one stored menu item to the full render shape (handles legacy data). */
    function normalize_menu_item(array $item): ?array
    {
        $label = trim((string) ($item['label'] ?? ''));
        if ($label === '') {
            return null;
        }

        // Legacy support: type 'category' + value (id) → resolve to a URL.
        $url = (string) ($item['url'] ?? '');
        if ($url === '' && ($item['type'] ?? '') === 'category') {
            $cat = \App\Models\Category::find((int) ($item['value'] ?? 0));
            $url = $cat ? route('category.show', $cat->slug) : '#';
        } elseif ($url === '') {
            $url = (string) ($item['value'] ?? '#');
        }

        $type = in_array($item['type'] ?? '', ['link', 'dropdown', 'mega'], true)
            ? $item['type']
            : (! empty($item['columns']) ? 'mega' : (! empty($item['children']) ? 'dropdown' : 'link'));

        $children = collect($item['children'] ?? [])->map(function ($c) {
            $cl = trim((string) ($c['label'] ?? ''));
            $cu = (string) ($c['url'] ?? $c['value'] ?? '#');
            return $cl === '' ? null : ['label' => $cl, 'url' => $cu, 'new_tab' => (bool) ($c['new_tab'] ?? false)];
        })->filter()->values()->all();

        $columns = collect($item['columns'] ?? [])->map(function ($col) {
            $links = collect($col['links'] ?? [])->map(function ($l) {
                $ll = trim((string) ($l['label'] ?? ''));
                return $ll === '' ? null : ['label' => $ll, 'url' => (string) ($l['url'] ?? '#'), 'new_tab' => (bool) ($l['new_tab'] ?? false)];
            })->filter()->values()->all();
            return empty($links) && trim((string) ($col['heading'] ?? '')) === '' ? null
                : ['heading' => trim((string) ($col['heading'] ?? '')), 'links' => $links];
        })->filter()->values()->all();

        return [
            'label' => $label,
            'type' => $type,
            'url' => $url ?: '#',
            'new_tab' => (bool) ($item['new_tab'] ?? false),
            'badge' => ($item['badge'] ?? null) ?: null,
            'view_all_mobile' => (bool) ($item['view_all_mobile'] ?? false),
            'children' => $children,
            'columns' => $columns,
        ];
    }
}

if (! function_exists('meta_pixel_id')) {
    /** Pixel ID from Appearance settings, falling back to .env. */
    function meta_pixel_id(): ?string
    {
        return theme('meta_pixel_id') ?: config('meta.pixel_id');
    }
}
