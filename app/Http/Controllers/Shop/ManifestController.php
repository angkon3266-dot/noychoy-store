<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;

/**
 * Web app manifest, generated from store settings so the branding follows
 * whatever store this codebase is deployed for.
 *
 * Required for iOS web push: Safari only delivers push to sites installed on
 * the Home Screen, and installing standalone requires a manifest.
 */
class ManifestController extends Controller
{
    public function __invoke()
    {
        $name = store_name();
        $icon = theme_asset(theme('favicon'));

        $icons = [];
        if ($icon) {
            $ext = strtolower(pathinfo(parse_url($icon, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            $icons[] = [
                'src' => $icon,
                'sizes' => 'any',
                'type' => match ($ext) {
                    'png' => 'image/png',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'svg' => 'image/svg+xml',
                    'webp' => 'image/webp',
                    default => 'image/x-icon',
                },
            ];
        }

        return response()->json([
            'name' => $name,
            'short_name' => Str::limit($name, 12, ''),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            // Matches the compiled CSS palette (body bg gold-50, primary gold-600).
            'background_color' => '#fbf8f1',
            'theme_color' => '#9a6c2e',
            'icons' => $icons,
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
