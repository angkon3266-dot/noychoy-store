<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductImage;
use App\Services\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Media library — browse, search, optimize (shrink) and delete uploaded images &
 * videos on the public disk, individually or in bulk.
 */
class MediaController extends Controller
{
    protected array $imageExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    protected array $videoExt = ['mp4', 'webm', 'mov', 'ogg', 'm4v'];

    public function index(Request $request)
    {
        $disk = Storage::disk('public');
        $type = $request->query('type', 'all');
        $folder = $request->query('folder');
        $q = trim((string) $request->query('q', ''));
        $view = $request->query('view') === 'list' ? 'list' : 'grid';

        // Map each stored file to the product that uses it (for "In use" + search by product).
        $productByPath = ProductImage::with('product:id,name')->get()
            ->filter(fn ($pi) => $pi->product)
            ->mapWithKeys(fn ($pi) => [$pi->path => $pi->product->name]);

        $items = collect($disk->allFiles())
            ->reject(fn ($p) => str_starts_with($p, 'fonts/'))
            ->map(function ($p) use ($disk, $productByPath) {
                $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
                $isImage = in_array($ext, $this->imageExt, true);
                $isVideo = in_array($ext, $this->videoExt, true);
                if (! $isImage && ! $isVideo) {
                    return null;
                }

                return [
                    'path' => $p,
                    'url' => $disk->url($p),
                    'size' => $disk->size($p),
                    'type' => $isVideo ? 'video' : 'image',
                    'ext' => $ext,
                    'optimizable' => in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true),
                    'folder' => str_contains($p, '/') ? explode('/', $p)[0] : '(root)',
                    'product' => $productByPath[$p] ?? null,
                    'in_use' => isset($productByPath[$p]),
                ];
            })
            ->filter()
            ->when($type !== 'all', fn ($c) => $c->where('type', $type))
            ->when($folder, fn ($c) => $c->where('folder', $folder))
            ->when($q !== '', fn ($c) => $c->filter(fn ($m) => str_contains(strtolower((string) $m['product']), strtolower($q))
                || str_contains(strtolower(basename($m['path'])), strtolower($q))))
            ->sortByDesc('size')
            ->values();

        $folders = collect($disk->allFiles())
            ->reject(fn ($p) => str_starts_with($p, 'fonts/'))
            ->map(fn ($p) => str_contains($p, '/') ? explode('/', $p)[0] : '(root)')
            ->unique()->sort()->values();

        return view('admin.media.index', [
            'items' => $items,
            'folders' => $folders,
            'type' => $type,
            'folder' => $folder,
            'q' => $q,
            'view' => $view,
            'totalSize' => $items->sum('size'),
            'count' => $items->count(),
        ]);
    }

    /** Optimize one or many files (slider-driven max width + quality). */
    public function optimize(Request $request, ImageOptimizer $optimizer)
    {
        $data = $request->validate([
            'paths' => ['required', 'array', 'min:1'],
            'paths.*' => ['string'],
            'max_width' => ['nullable', 'integer', 'min:200', 'max:4000'],
            'quality' => ['nullable', 'integer', 'min:30', 'max:95'],
        ]);

        $done = 0;
        $saved = 0;
        foreach ($data['paths'] as $path) {
            if (! $this->safePath($path)) {
                continue;
            }
            $res = $optimizer->optimizeExisting($path, $data['max_width'] ?? 1200, $data['quality'] ?? 75);
            if ($res) {
                $done++;
                $saved += max(0, $res['old_size'] - $res['new_size']);
            }
        }

        if ($done === 0) {
            return back()->with('error', 'Nothing optimized (unsupported files or image library unavailable).');
        }

        return back()->with('success', "Optimized {$done} file(s) — saved ".$this->human($saved).'.');
    }

    /** Delete one or many files. */
    public function destroy(Request $request)
    {
        $data = $request->validate([
            'paths' => ['required', 'array', 'min:1'],
            'paths.*' => ['string'],
        ]);

        $n = 0;
        foreach ($data['paths'] as $path) {
            if (! $this->safePath($path)) {
                continue;
            }
            Storage::disk('public')->delete($path);
            ProductImage::where('path', $path)->delete();
            $n++;
        }

        return back()->with('success', "Deleted {$n} file(s).");
    }

    protected function safePath(string $path): bool
    {
        return ! str_contains($path, '..') && ! str_starts_with($path, '/') && Storage::disk('public')->exists($path);
    }

    protected function human(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024).' KB';
        }

        return $bytes.' B';
    }
}
