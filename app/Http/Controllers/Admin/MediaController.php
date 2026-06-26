<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductImage;
use App\Services\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Media library — browse, optimize (shrink) and delete uploaded images & videos
 * stored on the public disk (products, branding, hero, sections, discover, reviews…).
 */
class MediaController extends Controller
{
    protected array $imageExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    protected array $videoExt = ['mp4', 'webm', 'mov', 'ogg', 'm4v'];

    public function index(Request $request)
    {
        $disk = Storage::disk('public');
        $type = $request->query('type', 'all');     // all | image | video
        $folder = $request->query('folder');        // optional folder filter

        // Paths currently used by product galleries (so we can warn before deleting).
        $referenced = ProductImage::pluck('path')->filter()->flip();

        $items = collect($disk->allFiles())
            ->reject(fn ($p) => str_starts_with($p, 'fonts/'))  // skip self-hosted font files
            ->map(function ($p) use ($disk, $referenced) {
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
                    'in_use' => $referenced->has($p),
                ];
            })
            ->filter()
            ->when($type !== 'all', fn ($c) => $c->where('type', $type))
            ->when($folder, fn ($c) => $c->where('folder', $folder))
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
            'totalSize' => $items->sum('size'),
            'count' => $items->count(),
        ]);
    }

    public function optimize(Request $request, ImageOptimizer $optimizer)
    {
        $data = $request->validate([
            'path' => ['required', 'string'],
            'max_width' => ['nullable', 'integer', 'min:200', 'max:4000'],
            'quality' => ['nullable', 'integer', 'min:30', 'max:95'],
        ]);

        if (! $this->safePath($data['path'])) {
            return back()->with('error', 'Invalid file path.');
        }

        $res = $optimizer->optimizeExisting($data['path'], $data['max_width'] ?? 1600, $data['quality'] ?? 80);
        if (! $res) {
            return back()->with('error', 'Could not optimize this file (unsupported type or image library unavailable).');
        }

        $saved = $res['old_size'] - $res['new_size'];
        $msg = 'Optimized: '.$this->human($res['old_size']).' → '.$this->human($res['new_size']).
            ($saved > 0 ? ' (saved '.$this->human($saved).')' : ' (already optimal)').
            ' · '.$res['width'].'×'.$res['height'].'px.';

        return back()->with('success', $msg);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['path' => ['required', 'string']]);

        if (! $this->safePath($data['path'])) {
            return back()->with('error', 'Invalid file path.');
        }

        Storage::disk('public')->delete($data['path']);
        ProductImage::where('path', $data['path'])->delete(); // clear any gallery reference

        return back()->with('success', 'File deleted.');
    }

    /** Reject path traversal / absolute paths. */
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
