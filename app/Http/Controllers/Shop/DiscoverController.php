<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Setting;

class DiscoverController extends Controller
{
    public function index()
    {
        $tiles = Setting::get('discover_tiles', []);
        $tiles = is_array($tiles) ? array_values(array_filter($tiles, fn ($t) => filled($t['image'] ?? null))) : [];

        // Fallback: if the admin hasn't set up Discover tiles, show top-level categories.
        $categories = $tiles ? collect() : Category::whereNull('parent_id')->where('is_active', true)->orderBy('name')->take(12)->get();

        return \Inertia\Inertia::render('Discover', [
            'pageTitle' => 'Discover',
            'tiles' => collect($tiles)->map(fn ($t) => [
                'image' => theme_asset($t['image']),
                'name' => $t['name'] ?? null,
                'link' => ($t['link'] ?? null) ?: '#',
            ])->values(),
            'categories' => $categories->map(fn ($c) => [
                'name' => $c->name,
                'url' => route('category.show', $c),
                'image' => theme_asset($c->image),
            ])->values(),
        ])->withViewData(['pageTitle' => 'Discover']);
    }
}
