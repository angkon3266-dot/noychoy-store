<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\ImageOptimizer;
use App\Services\MemberPricingService;
use App\Services\Meta\MetaProductMapper;
use App\Services\Meta\MetaSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (! function_exists('image_srcset')) {
    /**
     * A srcset for an uploaded image, from whatever variants exist on disk.
     *
     * Originals are stored at up to 1600px and most of the storefront was
     * handing that straight to the browser — a phone on a Bangladeshi mobile
     * connection downloading a desktop-sized picture for a tile 180px wide.
     * The 450 and 900 variants were already being generated; nothing ever
     * offered them.
     *
     * Returns null when no variant exists, so callers can fall back to `src`
     * alone rather than emitting a broken srcset.
     */
    function image_srcset(?string $urlOrPath, array $widths = [450, 900]): ?string
    {
        if (blank($urlOrPath)) {
            return null;
        }

        $parts = [];

        foreach ($widths as $w) {
            if ($url = image_variant($urlOrPath, $w)) {
                $parts[] = $url.' '.$w.'w';
            }
        }

        if (empty($parts)) {
            return null;
        }

        // The original is the largest option, for a big screen at 2x.
        $parts[] = $urlOrPath.' 1600w';

        return implode(', ', $parts);
    }
}

if (! function_exists('brand_font_css_url')) {
    /**
     * URL of the built self-hosted brand font stylesheet, or null.
     *
     * Laravel's @fonts directive would also emit a <link rel=preload> for every
     * font file in the manifest — 18 of them here, about 268 KB, forced down
     * the wire before the hero image. That defeats the whole point of the
     * unicode-range splits: left alone, a browser fetches only the two or three
     * subsets the page actually uses. So we link the stylesheet and let it.
     *
     * The filename is content-hashed, so it caches forever and the manifest is
     * only read once per boot.
     */
    function brand_font_css_url(): ?string
    {
        static $url = false;

        if ($url !== false) {
            return $url;
        }

        $manifest = public_path('build/fonts-manifest.json');

        if (! is_file($manifest)) {
            return $url = null;
        }

        $file = json_decode((string) file_get_contents($manifest), true)['style']['file'] ?? null;

        return $url = $file ? asset('build/'.$file) : null;
    }
}

if (! function_exists('store_time')) {
    /**
     * A stored timestamp, moved into the shop's local wall clock for display.
     *
     * Timestamps are stored in UTC. Dhaka is UTC+6, so an order placed at 2am
     * local was stored under yesterday's date — and printing it raw tells the
     * customer their order was placed on the wrong day.
     */
    function store_time(?\Illuminate\Support\Carbon $at): ?\Illuminate\Support\Carbon
    {
        return $at?->copy()->setTimezone(config('store.timezone', 'Asia/Dhaka'));
    }
}

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

if (! function_exists('free_shipping_threshold')) {
    /**
     * Subtotal at or above which delivery is free, or null when the promise is
     * switched off entirely.
     *
     * This is the ONE place the number is resolved. The cart, the checkout and
     * the announcement bar all read it here, so the banner can never advertise
     * a threshold the checkout does not honour. Admin → Settings wins; the
     * env/config value is only the fallback for a fresh install.
     *
     * Zero and blank both mean "disabled" — a genuinely free-for-everyone
     * store is a free-shipping Offer, not a threshold of 0.
     */
    function free_shipping_threshold(): ?float
    {
        $value = Setting::get('free_shipping_threshold', config('store.shipping.free_threshold'));

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }
}

if (! function_exists('announcement_messages')) {
    /**
     * Announcement-bar messages with the free-delivery promise resolved.
     *
     * A message containing {free_delivery} prints the live threshold. When the
     * promise is switched off the whole message is DROPPED rather than rendered
     * with a hole in it — that is the point: the bar cannot keep advertising
     * free delivery after the checkout stopped honouring it.
     *
     * @return array<int, string>
     */
    function announcement_messages(): array
    {
        $threshold = free_shipping_threshold();

        return array_values(array_filter(array_map(function ($message) use ($threshold) {
            $message = trim((string) $message);

            if (! str_contains($message, '{free_delivery}')) {
                return $message;
            }

            return $threshold === null ? '' : str_replace('{free_delivery}', money($threshold), $message);
        }, (array) (theme('announcement_messages') ?? []))));
    }
}

if (! function_exists('member_pricing')) {
    /** Shared MemberPricingService instance. */
    function member_pricing(): MemberPricingService
    {
        return app(MemberPricingService::class);
    }
}

if (! function_exists('is_member')) {
    /** Is a storefront customer logged in? */
    function is_member(): bool
    {
        return auth('customer')->check();
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
        $cats = Category::query()->where('is_active', true)->get();
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

if (! function_exists('menu_target_url')) {
    /**
     * Resolve a menu entry's destination.
     *
     * A collection-targeted entry stores its URL at save time (see
     * MenuController::sanitize) so the storefront pays nothing; this only
     * back-fills an entry whose URL is missing — legacy rows, or a collection
     * picked before the URL was written.
     */
    function menu_target_url(array $entry, string $url): string
    {
        if ($url !== '' && $url !== '#') {
            return $url;
        }

        if (($entry['target'] ?? null) === 'collection' && ($id = (int) ($entry['collection_id'] ?? 0))) {
            $collection = \App\Models\Collection::find($id);

            return $collection ? route('collection.show', $collection->slug) : '#';
        }

        return $url;
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
            $cat = Category::find((int) ($item['value'] ?? 0));
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

            $cu = menu_target_url($c, $cu);

            return $cl === '' ? null : [
                'label' => $cl, 'url' => $cu, 'new_tab' => (bool) ($c['new_tab'] ?? false),
                'target' => $c['target'] ?? 'custom',
                'collection_id' => isset($c['collection_id']) ? (int) $c['collection_id'] : null,
            ];
        })->filter()->values()->all();

        $columns = collect($item['columns'] ?? [])->map(function ($col) {
            $links = collect($col['links'] ?? [])->map(function ($l) {
                $ll = trim((string) ($l['label'] ?? ''));

                return $ll === '' ? null : [
                    'label' => $ll,
                    'url' => menu_target_url($l, (string) ($l['url'] ?? '#')),
                    'new_tab' => (bool) ($l['new_tab'] ?? false),
                    'target' => $l['target'] ?? 'custom',
                    'collection_id' => isset($l['collection_id']) ? (int) $l['collection_id'] : null,
                ];
            })->filter()->values()->all();

            return empty($links) && trim((string) ($col['heading'] ?? '')) === '' ? null
                : ['heading' => trim((string) ($col['heading'] ?? '')), 'links' => $links];
        })->filter()->values()->all();

        return [
            'label' => $label,
            'type' => $type,
            'url' => menu_target_url($item, $url) ?: '#',
            'new_tab' => (bool) ($item['new_tab'] ?? false),
            'badge' => ($item['badge'] ?? null) ?: null,
            'view_all_mobile' => (bool) ($item['view_all_mobile'] ?? false),
            'target' => $item['target'] ?? 'custom',
            'collection_id' => isset($item['collection_id']) ? (int) $item['collection_id'] : null,
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
     * Pixel ID — one editable home (Meta Integration → Tracking), with .env as
     * the deploy-time fallback for a fresh install.
     *
     * Appearance used to hold a second copy that lost this precedence contest.
     * Because the loser still rendered in its own form field, an admin could
     * read one Pixel ID on screen while a different one fired on the storefront
     * — which is exactly what happened. A setting with two editors has no
     * single answer to "what is it set to", so there is now only one.
     */
    function meta_pixel_id(): ?string
    {
        $id = app(MetaSettings::class)->pixelId() ?: config('meta.pixel_id');

        // Normalise "" (an unset env var) to null: callers gate the whole Pixel
        // snippet on this, and "not configured" should read as absent rather
        // than as a Pixel whose id happens to be blank.
        return filled($id) ? (string) $id : null;
    }
}

if (! function_exists('image_variant')) {
    /**
     * Public URL of a pre-generated downscaled sibling of a stored image
     * (see ImageOptimizer::variant()), or null when none exists.
     *
     * Accepts either a relative disk path or the full /storage URL the
     * accessors hand out. Deliberately a lookup only — it never generates,
     * so a page view costs one file stat, not an image encode.
     */
    function image_variant(?string $urlOrPath, int $width = 450): ?string
    {
        if (blank($urlOrPath)) {
            return null;
        }

        $path = Str::startsWith($urlOrPath, ['http://', 'https://', '/'])
            ? public_url_to_path($urlOrPath)
            : $urlOrPath;

        if (blank($path) || Str::startsWith($path, ['http://', 'https://'])) {
            return null; // remote image — nothing of ours to look up
        }

        $variant = app(ImageOptimizer::class)->variantPath($path, $width);

        return Storage::disk('public')->exists($variant)
            ? Storage::disk('public')->url($variant)
            : null;
    }
}

if (! function_exists('bd_phone')) {
    /**
     * Normalise any Bangladeshi mobile number to the bare local 11-digit form
     * "01XXXXXXXXX" — strips spaces, dashes, and a +880 / 880 country prefix.
     * Best-effort: returns the cleaned digits even if not a perfect match.
     */
    function bd_phone(?string $phone): string
    {
        $d = preg_replace('/\D/', '', (string) $phone) ?? '';

        if (str_starts_with($d, '880')) {
            $d = substr($d, 3);
        }
        if (strlen($d) === 10 && str_starts_with($d, '1')) {
            $d = '0'.$d; // 1XXXXXXXXX → 01XXXXXXXXX
        }

        return $d;
    }
}

if (! function_exists('wa_phone')) {
    /**
     * International form for WhatsApp / wa.me links: 8801XXXXXXXXX, no plus.
     * Numbers are stored locally as 01XXXXXXXXX; this converts at the boundary,
     * the same way SmsService does for the SMS gateway.
     */
    function wa_phone(?string $phone): string
    {
        $d = bd_phone($phone);

        return $d === '' ? '' : '880'.ltrim($d, '0');
    }
}

if (! function_exists('tel_link')) {
    /**
     * A tel: link for a customer.
     *
     * Uses the full international form with a leading + so the number dials
     * correctly from a desktop softphone or a phone roaming abroad, where a
     * local 01… number has no country to belong to.
     */
    function tel_link(?string $phone): ?string
    {
        $to = wa_phone($phone);

        return $to === '' ? null : 'tel:+'.$to;
    }
}

if (! function_exists('wa_link')) {
    /** A wa.me link for a customer, optionally pre-filling the first message. */
    function wa_link(?string $phone, ?string $message = null): ?string
    {
        $to = wa_phone($phone);
        if ($to === '') {
            return null;
        }

        return 'https://wa.me/'.$to.(filled($message) ? '?text='.rawurlencode($message) : '');
    }
}

if (! function_exists('meta_content_id')) {
    /**
     * The Meta content id for a product (optionally a variant). This MUST equal
     * the catalog item's retailer_id so Pixel/CAPI events link to catalog
     * products (retargeting / Advantage+). Delegates to MetaProductMapper so
     * there is a single source of truth: "prod-{id}" / "prod-{id}-var-{vid}".
     */
    function meta_content_id(Product $product, ?ProductVariant $variant = null): string
    {
        return app(MetaProductMapper::class)->retailerId($product, $variant);
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
    function resolve_media(Request $request, string $field, string $dir = 'uploads'): ?string
    {
        if ($request->hasFile($field)) {
            return app(ImageOptimizer::class)->storeWebp($request->file($field), $dir);
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
        return app(ImageOptimizer::class)->storeWebpFromUrl($url, $dir);
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
