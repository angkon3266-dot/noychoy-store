<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Browse / edit the AI knowledge base (the /knowledge markdown files) from
 * the admin panel, and run the product sync on demand.
 */
class KnowledgeController extends Controller
{
    public function index(Request $request)
    {
        $base = $this->basePath();

        // Folder → files listing (top-level dirs only; knowledge is flat by design).
        $tree = collect(File::directories($base))
            ->mapWithKeys(function ($dir) {
                $files = collect(File::files($dir))
                    ->filter(fn ($f) => $f->getExtension() === 'md')
                    ->map(fn ($f) => basename(dirname($f)).'/'.$f->getFilename())
                    ->values();

                return [basename($dir) => $files];
            })
            ->sortKeys();

        $rootFiles = collect(File::files($base))
            ->filter(fn ($f) => $f->getExtension() === 'md')
            ->map(fn ($f) => $f->getFilename())->values();

        $selected = null;
        $content = null;
        if ($request->filled('file') && ($path = $this->resolve($request->query('file')))) {
            $selected = $request->query('file');
            $content = File::get($path);
        }

        return view('admin.knowledge.index', [
            'tree' => $tree,
            'rootFiles' => $rootFiles,
            'selected' => $selected,
            'content' => $content,
            'productCount' => \App\Models\Product::count(),
            'fileCount' => $tree->flatten()->count() + $rootFiles->count(),
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:200000'],
        ]);

        $path = $this->resolve($data['file']);
        if (! $path) {
            return back()->with('error', 'That file isn’t part of the knowledge base.');
        }

        // Normalise editor CRLF so the sync command's front-matter parsing
        // and git diffs stay clean.
        File::put($path, str_replace("\r\n", "\n", $data['content']));

        return redirect()->route('admin.knowledge.index', ['file' => $data['file']])
            ->with('success', 'Saved '.$data['file'].'.');
    }

    public function sync()
    {
        Artisan::call('knowledge:sync');

        return back()->with('success', trim(Artisan::output()));
    }

    protected function basePath(): string
    {
        return base_path('knowledge');
    }

    /** Resolve a relative file safely inside /knowledge; null when invalid. */
    protected function resolve(string $relative): ?string
    {
        if (! str_ends_with($relative, '.md')) {
            return null;
        }
        $path = realpath($this->basePath().DIRECTORY_SEPARATOR.$relative);
        $base = realpath($this->basePath());

        return ($path && $base && str_starts_with($path, $base.DIRECTORY_SEPARATOR)) ? $path : null;
    }
}
