<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Setting;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function index()
    {
        return view('admin.menu', [
            // site_menu() already returns the full render shape (stored or default).
            'items' => site_menu(),
            'categories' => Category::orderBy('name')->get(['id', 'name', 'slug', 'parent_id']),
            // Collections the admin has offered to the menu (Collections →
            // "Offer in the menu builder"), with their URLs pre-resolved.
            'collections' => Collection::inMenu()->orderBy('position')->orderBy('name')->get(['id', 'name', 'slug'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'url' => route('collection.show', $c->slug)])
                ->values(),
            'theme' => theme(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'menu_json' => ['nullable', 'string'],
            'menu_desktop_trigger' => ['required', 'in:hover,click'],
            'menu_show_search' => ['nullable', 'boolean'],
            'menu_cta_label' => ['nullable', 'string', 'max:40'],
            'menu_cta_link' => ['nullable', 'string', 'max:255'],
        ]);

        $decoded = json_decode($data['menu_json'] ?? '[]', true);
        Setting::put('menu', is_array($decoded) ? $this->sanitize($decoded) : []);

        $theme = theme();
        $theme['menu_desktop_trigger'] = $data['menu_desktop_trigger'];
        $theme['menu_show_search'] = $request->boolean('menu_show_search');
        $theme['menu_cta_label'] = $data['menu_cta_label'] ?? null;
        $theme['menu_cta_link'] = $data['menu_cta_link'] ?? null;
        Setting::put('theme', $theme);

        return back()->with('success', 'Menu saved.');
    }

    /** Clean posted items into the stored mega-menu structure. */
    /**
     * Resolve a menu entry's target into the stored {url, target, collection_id}.
     *
     * A collection's slug never changes on rename (Collection::booted only
     * generates a blank slug), so the URL written here stays correct for the
     * life of the collection.
     */
    protected function target(array $entry): array
    {
        $target = $entry['target'] ?? 'custom';
        $target = in_array($target, ['custom', 'collection'], true) ? $target : 'custom';
        $collectionId = ($entry['collection_id'] ?? null) ? (int) $entry['collection_id'] : null;
        $url = trim((string) ($entry['url'] ?? '#'));

        if ($target === 'collection' && $collectionId) {
            $collection = Collection::find($collectionId);
            $url = $collection ? route('collection.show', $collection->slug) : '#';
        } else {
            $collectionId = null;
        }

        return ['url' => $url ?: '#', 'target' => $target, 'collection_id' => $collectionId];
    }

    protected function sanitize(array $items): array
    {
        $clean = [];
        foreach ($items as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $type = $item['type'] ?? 'link';
            $type = in_array($type, ['link', 'dropdown', 'mega'], true) ? $type : 'link';

            $children = [];
            foreach ($item['children'] ?? [] as $c) {
                $cl = trim((string) ($c['label'] ?? ''));
                if ($cl === '') {
                    continue;
                }
                $children[] = array_merge([
                    'label' => mb_substr($cl, 0, 60),
                    'new_tab' => (bool) ($c['new_tab'] ?? false),
                ], $this->target($c));
            }

            $columns = [];
            foreach ($item['columns'] ?? [] as $col) {
                $links = [];
                foreach ($col['links'] ?? [] as $l) {
                    $ll = trim((string) ($l['label'] ?? ''));
                    if ($ll === '') {
                        continue;
                    }
                    $links[] = array_merge([
                        'label' => mb_substr($ll, 0, 60),
                        'new_tab' => (bool) ($l['new_tab'] ?? false),
                    ], $this->target($l));
                }
                $heading = trim((string) ($col['heading'] ?? ''));
                if ($heading !== '' || ! empty($links)) {
                    $columns[] = ['heading' => mb_substr($heading, 0, 60), 'links' => $links];
                }
            }

            $clean[] = array_merge([
                'label' => mb_substr($label, 0, 60),
                'type' => $type,
                'new_tab' => (bool) ($item['new_tab'] ?? false),
                'badge' => mb_substr(trim((string) ($item['badge'] ?? '')), 0, 30) ?: null,
                'view_all_mobile' => (bool) ($item['view_all_mobile'] ?? false),
                'children' => $children,
                'columns' => $columns,
            ], $this->target($item));
        }

        return $clean;
    }
}
