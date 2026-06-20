<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $featured = Product::published()->featured()->with('images', 'approvedReviews', 'category')->latest()->take(8)->get();
        $newArrivals = Product::published()->with('images', 'approvedReviews', 'category')->latest()->take(8)->get();
        $categories = Category::active()->whereNull('parent_id')->orderBy('position')->take(6)->get();

        // Logged-in admins can preview any template via ?preview_home=KEY without saving it.
        $key = theme('homepage_template');
        if ($request->filled('preview_home') && auth('web')->check()
            && config('theme.homepage_templates.'.$request->query('preview_home'))) {
            $key = $request->query('preview_home');
        }

        $view = config('theme.homepage_templates.'.$key.'.view');
        if (! $view || ! view()->exists($view)) {
            $view = 'shop.templates.home.aurelia';
        }

        return view($view, compact('featured', 'newArrivals', 'categories'));
    }
}
