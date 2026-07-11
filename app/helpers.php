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

if (! function_exists('upload_limit_mb')) {
    /**
     * The real maximum file-upload size this server allows, in whole MB.
     * It's the smaller of php.ini's upload_max_filesize and post_max_size —
     * uploads larger than this are silently dropped before Laravel sees them
     * (the usual cause of "my video won't upload" on shared hosting).
     */
    function upload_limit_mb(): int
    {
        $toMb = function ($value): int {
            $value = trim((string) $value);
            if ($value === '') {
                return 0;
            }
            $unit = strtolower(substr($value, -1));
            $num = (float) $value;

            return (int) match ($unit) {
                'g' => $num * 1024,
                'm' => $num,
                'k' => ceil($num / 1024),
                default => ceil($num / 1048576), // bare bytes
            };
        };

        $limits = array_filter([
            $toMb(ini_get('upload_max_filesize')),
            $toMb(ini_get('post_max_size')),
        ]);

        return $limits ? max(1, (int) min($limits)) : 8;
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

if (! function_exists('store_name')) {
    /**
     * The store's display name. Prefers the admin-editable `store_name` setting,
     * falling back to config (env). Used for both storefront and admin branding
     * so a deployment never shows "Laravel".
     */
    function store_name(): string
    {
        return (string) (Setting::get('store_name') ?: config('store.name') ?: config('app.name'));
    }
}

if (! function_exists('page_content')) {
    /**
     * Editable footer-page content (privacy/terms/refund/contact), stored in the
     * `pages` setting and falling back to config/pages.php defaults.
     *
     * page_content('privacy') → ['title'=>..,'body'=>..]
     * page_content('privacy', 'title') → the title string
     */
    function page_content(string $page, ?string $field = null)
    {
        $saved = Setting::get('pages', []);
        $saved = is_array($saved) ? $saved : [];

        $merged = array_merge(
            (array) config('pages.'.$page, []),
            (array) ($saved[$page] ?? []),
        );

        if ($field === null) {
            return $merged;
        }

        $value = $merged[$field] ?? null;

        return ($value === null || $value === '') ? config('pages.'.$page.'.'.$field) : $value;
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

if (! function_exists('color_hex')) {
    /** Best-effort hex for a colour name (for filter swatches). Null if unknown. */
    function color_hex(string $name): ?string
    {
        $key = strtolower(trim($name));
        $map = [
            'black' => '#111111', 'white' => '#ffffff', 'off white' => '#f4f1ea', 'offwhite' => '#f4f1ea',
            'red' => '#e11d48', 'maroon' => '#7f1d1d', 'burgundy' => '#7f1d1d',
            'blue' => '#1d4ed8', 'navy' => '#1e293b', 'sky blue' => '#7dd3fc', 'slate blue' => '#64748b', 'royal blue' => '#1d4ed8',
            'green' => '#16a34a', 'mint' => '#a7f3d0', 'olive' => '#6b7d3a', 'teal' => '#0d9488',
            'yellow' => '#facc15', 'mustard' => '#d4a017', 'gold' => '#b6863a',
            'orange' => '#f97316', 'pastel orange' => '#fdba74', 'biscuit' => '#e3c79a', 'beige' => '#e3d5b8', 'cream' => '#f5edda',
            'pink' => '#ec4899', 'purple' => '#7e22ce', 'plum' => '#7e22ce', 'brown' => '#8b5e3c', 'tan' => '#d2b48c',
            'grey' => '#9ca3af', 'gray' => '#9ca3af', 'silver' => '#c0c0c0', 'charcoal' => '#36454f',
        ];
        if (isset($map[$key])) {
            return $map[$key];
        }
        if (str_starts_with($key, 'multi')) {
            return 'multi';
        }
        // Allow raw hex values stored as the attribute value.
        if (preg_match('/^#?[0-9a-f]{6}$/i', $key)) {
            return '#'.ltrim($key, '#');
        }
        return null;
    }
}

if (! function_exists('meta_pixel_id')) {
    /**
     * Pixel ID, resolved from the database first: the Meta Integration settings
     * (MetaSettings->pixel_id) win, then the Appearance/theme setting, then the
     * .env fallback — so the admin's per-store value drives both Pixel & CAPI.
     */
    function meta_pixel_id(): ?string
    {
        return app(\App\Services\Meta\MetaSettings::class)->pixelId()
            ?: (theme('meta_pixel_id') ?: config('meta.pixel_id'));
    }
}

if (! function_exists('meta_content_id')) {
    /**
     * The Meta content id for a product (optionally a variant). This MUST equal
     * the catalog item's retailer_id so Pixel/CAPI events link to catalog
     * products (retargeting / Advantage+). Delegates to MetaProductMapper so
     * there is a single source of truth: "prod-{id}" / "prod-{id}-var-{vid}".
     */
    function meta_content_id(\App\Models\Product $product, ?\App\Models\ProductVariant $variant = null): string
    {
        return app(\App\Services\Meta\MetaProductMapper::class)->retailerId($product, $variant);
    }
}

if (! function_exists('youtube_id')) {
    /** Extract the 11-char video id from any YouTube URL form, or null. */
    function youtube_id(string $url): ?string
    {
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/|v/))([A-Za-z0-9_-]{11})~', $url, $m)) {
            return $m[1];
        }
        if (preg_match('~^[A-Za-z0-9_-]{11}$~', trim($url))) {
            return trim($url);
        }
        return null;
    }
}

if (! function_exists('video_meta')) {
    /**
     * Normalise a video reference (YouTube/Vimeo URL or a stored file path)
     * into a render-ready shape.
     *
     * @return array{type:string, embed:?string, thumb:?string, src:?string}|null
     */
    function video_meta(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        if ($id = youtube_id($url)) {
            return [
                'type' => 'youtube',
                'embed' => "https://www.youtube.com/embed/{$id}",
                'thumb' => "https://i.ytimg.com/vi/{$id}/hqdefault.jpg",
                'src' => null,
            ];
        }

        // Vimeo
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            return ['type' => 'vimeo', 'embed' => "https://player.vimeo.com/video/{$m[1]}", 'thumb' => null, 'src' => null];
        }

        // Stored / uploaded file (mp4, webm…) — relative path on public disk or absolute URL.
        $src = Str::startsWith($url, ['http://', 'https://', '/'])
            ? $url
            : Storage::disk('public')->url($url);

        return ['type' => 'file', 'embed' => null, 'thumb' => null, 'src' => $src];
    }
}

if (! function_exists('resolve_media')) {
    /**
     * Resolve an image posted by <x-media-field> into a stored public-disk path.
     * Device upload wins; otherwise a companion "{field}_url" (media-library pick
     * or remote URL) is imported. Library picks that already live on our public
     * disk are reused in place (no wasteful re-copy). Returns null if neither.
     */
    function resolve_media(\Illuminate\Http\Request $request, string $field, string $dir = 'uploads'): ?string
    {
        if ($request->hasFile($field)) {
            return app(\App\Services\ImageOptimizer::class)->storeWebp($request->file($field), $dir);
        }

        $url = trim((string) $request->input($field.'_url', ''));
        if ($url === '') {
            return null;
        }

        // A pick from our own library — map the public URL back to its stored path.
        if ($existing = public_url_to_path($url)) {
            return $existing;
        }

        // A remote URL (e.g. pasted from elsewhere) — download & optimise a copy.
        return app(\App\Services\ImageOptimizer::class)->storeWebpFromUrl($url, $dir);
    }
}

if (! function_exists('public_url_to_path')) {
    /**
     * If a URL points at a file on our own "public" disk, return its relative
     * storage path (so it can be reused without copying); otherwise null.
     */
    function public_url_to_path(string $url): ?string
    {
        $base = rtrim(Storage::disk('public')->url(''), '/').'/';
        $path = null;

        if (Str::startsWith($url, $base)) {
            $path = Str::after($url, $base);
        } elseif (Str::startsWith($url, '/storage/')) {
            $path = Str::after($url, '/storage/');
        }

        if ($path === null) {
            return null;
        }

        $path = urldecode($path);

        return Storage::disk('public')->exists($path) ? $path : null;
    }
}
