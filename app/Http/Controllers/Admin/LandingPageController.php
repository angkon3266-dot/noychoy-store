<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LandingPageController extends Controller
{
    public function index()
    {
        return view('admin.landing.index', [
            'pages' => LandingPage::latest()->paginate(20),
        ]);
    }

    public function create()
    {
        return $this->form(new LandingPage(['blocks' => [], 'product_ids' => [], 'show_header' => true, 'show_footer' => true]));
    }

    public function edit(LandingPage $landing)
    {
        return $this->form($landing);
    }

    protected function form(LandingPage $page)
    {
        return view('admin.landing.form', [
            'page' => $page,
            'allProducts' => Product::orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'thumb' => $p->thumbnail])->values(),
            'allCategories' => \App\Models\Category::orderBy('name')->get(['id', 'name']),
            'recentReviews' => \App\Models\Review::approved()->with('product:id,name')
                ->latest()->take(60)->get(['id', 'product_id', 'author_name', 'rating', 'title', 'body']),
        ]);
    }

    public function store(Request $request)
    {
        $page = LandingPage::create($this->validated($request, null));
        $this->saveBlockImages($request, $page);

        return redirect()->route('admin.landing.edit', $page)->with('success', 'Landing page created.');
    }

    public function update(Request $request, LandingPage $landing)
    {
        $landing->update($this->validated($request, $landing));
        $this->saveBlockImages($request, $landing);

        return back()->with('success', 'Landing page saved.');
    }

    public function destroy(LandingPage $landing)
    {
        $landing->delete();

        return redirect()->route('admin.landing.index')->with('success', 'Landing page deleted.');
    }

    /** Duplicate a page (handy for seasonal variants of a proven funnel). */
    public function duplicate(LandingPage $landing)
    {
        $copy = $landing->replicate(['slug', 'views']);
        $copy->title = $landing->title.' (copy)';
        $copy->slug = LandingPage::uniqueSlug($copy->title);
        $copy->is_published = false;
        $copy->views = 0;
        $copy->save();

        return redirect()->route('admin.landing.edit', $copy)->with('success', 'Copied — edit and publish when ready.');
    }

    protected function validated(Request $request, ?LandingPage $page): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9\-]+$/'],
            'is_published' => ['nullable', 'boolean'],
            'show_header' => ['nullable', 'boolean'],
            'show_footer' => ['nullable', 'boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'og_image' => ['nullable', 'string', 'max:255'],
            'blocks_json' => ['nullable', 'string'],
        ]);

        $blocks = json_decode((string) ($data['blocks_json'] ?? ''), true);

        return [
            'title' => $data['title'],
            'slug' => filled($data['slug'] ?? null)
                ? LandingPage::uniqueSlug($data['slug'], $page?->id)
                : ($page?->slug ?: LandingPage::uniqueSlug($data['title'])),
            'is_published' => $request->boolean('is_published'),
            'show_header' => $request->boolean('show_header'),
            'show_footer' => $request->boolean('show_footer'),
            'product_ids' => array_values(array_unique(array_map('intval', $data['product_ids'] ?? []))),
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'og_image' => $data['og_image'] ?? null,
            'blocks' => is_array($blocks) ? $blocks : ($page?->blocks ?? []),
        ];
    }

    /**
     * Store uploaded block images and write their paths back into the saved
     * blocks (same convention as the homepage builder).
     */
    protected function saveBlockImages(Request $request, LandingPage $page): void
    {
        $blocks = $page->blocks ?? [];
        $changed = false;

        foreach ((array) $request->file('block_image', []) as $bi => $images) {
            foreach ((array) $images as $ii => $file) {
                if ($file) {
                    $blocks[$bi]['images'][$ii]['image'] = $file->store('sections', 'public');
                    $changed = true;
                }
            }
        }
        foreach ((array) $request->file('block_hero', []) as $bi => $file) {
            if ($file) {
                $blocks[$bi]['hero']['image'] = $file->store('sections', 'public');
                $changed = true;
            }
        }
        foreach ((array) $request->file('block_cta', []) as $bi => $file) {
            if ($file) {
                $blocks[$bi]['cta']['image'] = $file->store('sections', 'public');
                $changed = true;
            }
        }
        if ($file = $request->file('og_image_file')) {
            $page->og_image = $file->store('branding', 'public');
            $page->save();
        }

        if ($changed) {
            $page->update(['blocks' => $blocks]);
        }
    }
}
