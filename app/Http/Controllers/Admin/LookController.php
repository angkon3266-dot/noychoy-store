<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Look;
use App\Models\Product;
use App\Support\Storefront\StylePieces;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Admin → Shop the look (Nov 2026): styled photos with products pinned on
 * them, and the cut-out images the earring try-on and the stacker draw.
 */
class LookController extends Controller
{
    public function index()
    {
        return view('admin.looks.index', [
            'looks' => Look::orderBy('position')->orderByDesc('id')->get(),
        ]);
    }

    public function create()
    {
        return $this->form(new Look(['is_active' => true]));
    }

    public function edit(Look $look)
    {
        return $this->form($look);
    }

    protected function form(Look $look)
    {
        return view('admin.looks.form', [
            'look' => $look,
            'products' => Product::published()->with('images')->orderBy('name')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'thumb' => image_variant($p->thumbnail) ?: $p->thumbnail])
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $look = new Look;
        $this->save($request, $look);

        return redirect()->route('admin.looks.edit', $look)->with('success', 'Look saved. Click the photo to pin the pieces in it.');
    }

    public function update(Request $request, Look $look)
    {
        $this->save($request, $look);

        return redirect()->route('admin.looks.edit', $look)->with('success', 'Look saved.');
    }

    protected function save(Request $request, Look $look): void
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'caption' => ['nullable', 'string', 'max:300'],
            'image' => [$look->exists ? 'nullable' : 'required_without:image_url', 'nullable', 'file', 'image', 'max:6144'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'hotspots' => ['nullable', 'string', 'max:20000'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [
            'image.required_without' => 'Add a photo for the look.',
        ]);

        if ($path = resolve_media($request, 'image', 'looks')) {
            $look->image = $path;
        }
        abort_unless($look->image, 422, 'A look needs a photo.');

        $spots = json_decode((string) ($data['hotspots'] ?? '[]'), true);
        $look->fill([
            'title' => $data['title'],
            'caption' => $data['caption'] ?? null,
            'hotspots' => is_array($spots) ? $spots : [],
            'is_active' => $request->boolean('is_active'),
            'position' => (int) ($data['position'] ?? 0),
        ]);
        // Through the model's own cleaner: numeric, inside the photo, one product each.
        $look->hotspots = $look->cleanHotspots();
        $look->save();
    }

    public function destroy(Look $look)
    {
        $look->delete();

        return redirect()->route('admin.looks.index')->with('success', 'Look deleted.');
    }

    /* ── Cut-outs ─────────────────────────────────────────────────────────── */

    public function cutouts()
    {
        $products = StylePieces::ofKinds(['earring', 'ring', 'bangle', 'bracelet'], 500);

        return view('admin.looks.cutouts', [
            'groups' => $products->groupBy(fn ($p) => StylePieces::kind($p))->sortKeys(),
        ]);
    }

    public function saveCutout(Request $request, Product $product)
    {
        $request->validate([
            // PNG or WebP keep the transparency the drawing depends on.
            'cutout' => ['required', 'file', 'mimes:png,webp', 'max:4096'],
        ], ['cutout.mimes' => 'Use a PNG or WebP with a transparent background.']);

        // storeWebp keeps the alpha channel (ImageOptimizer::prepare).
        $path = app(\App\Services\ImageOptimizer::class)->storeWebp($request->file('cutout'), 'cutouts', 900, 90);
        $old = $product->cutout_image;
        $product->forceFill(['cutout_image' => $path])->saveQuietly();
        if ($old && ! str_starts_with($old, 'http')) {
            Storage::disk('public')->delete($old);
        }

        return back()->with('success', "Cut-out saved for {$product->name}.");
    }

    public function removeCutout(Product $product)
    {
        if ($product->cutout_image && ! str_starts_with($product->cutout_image, 'http')) {
            Storage::disk('public')->delete($product->cutout_image);
        }
        $product->forceFill(['cutout_image' => null])->saveQuietly();

        return back()->with('success', "Cut-out removed from {$product->name}.");
    }
}
