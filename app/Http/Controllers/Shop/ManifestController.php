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
    public function __invoke(\Illuminate\Http\Request $request)
    {
        // ?admin=1 installs the admin panel as its own Home-Screen app, which is
        // what iOS requires before it will deliver new-order alerts to a phone.
        $admin = $request->boolean('admin');
        $name = $admin ? store_name().' Admin' : store_name();
        $icon = theme_asset(theme('favicon'));

        // Android needs a 192 and a 512 to offer "Add to Home Screen" at all, and
        // a maskable entry so the icon is not letterboxed inside the OS shape.
        // A single sizes:"any" entry used to be all this emitted.
        $icons = [];
        if ($icon) {
            $ext = strtolower(pathinfo(parse_url($icon, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            $type = match ($ext) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'svg' => 'image/svg+xml',
                'webp' => 'image/webp',
                default => 'image/x-icon',
            };

            foreach (['192x192', '512x512'] as $size) {
                $icons[] = ['src' => $icon, 'sizes' => $size, 'type' => $type, 'purpose' => 'any'];
            }
            $icons[] = ['src' => $icon, 'sizes' => '512x512', 'type' => $type, 'purpose' => 'maskable'];
        } else {
            // The shipped fallback mark, so an install prompt exists before the
            // owner has uploaded anything.
            $icons[] = ['src' => asset('favicon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'];
            $icons[] = ['src' => asset('favicon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'maskable'];
        }

        return response()->json([
            'name' => $name,
            'short_name' => Str::limit($name, 12, ''),
            'start_url' => $admin ? '/admin' : '/',
            // Scope stays '/' either way so a push that opens an order page (or a
            // storefront link) keeps the user inside the installed app.
            'scope' => '/',
            'display' => 'standalone',
            // Follow the store's own palette rather than hardcoding the gold —
            // these fall back to the compiled defaults (gold-50 / gold-600).
            'background_color' => theme('background') ?: '#fbf8f1',
            'theme_color' => theme('primary') ?: '#9a6c2e',
            'icons' => $icons,
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
