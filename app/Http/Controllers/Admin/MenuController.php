<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
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
    protected function sanitize(array $items): array
    {
        $clean = [];
        foreach ($items as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $type = in_array($item['type'] ?? 'link', ['link', 'dropdown', 'mega'], true) ? $item['type'] : 'link';

            $children = [];
            foreach ($item['children'] ?? [] as $c) {
                $cl = trim((string) ($c['label'] ?? ''));
                if ($cl === '') {
                    continue;
                }
                $children[] = [
                    'label' => mb_substr($cl, 0, 60),
                    'url' => trim((string) ($c['url'] ?? '#')),
                    'new_tab' => (bool) ($c['new_tab'] ?? false),
                ];
            }

            $columns = [];
            foreach ($item['columns'] ?? [] as $col) {
                $links = [];
                foreach ($col['links'] ?? [] as $l) {
                    $ll = trim((string) ($l['label'] ?? ''));
                    if ($ll === '') {
                        continue;
                    }
                    $links[] = [
                        'label' => mb_substr($ll, 0, 60),
                        'url' => trim((string) ($l['url'] ?? '#')),
                        'new_tab' => (bool) ($l['new_tab'] ?? false),
                    ];
                }
                $heading = trim((string) ($col['heading'] ?? ''));
                if ($heading !== '' || ! empty($links)) {
                    $columns[] = ['heading' => mb_substr($heading, 0, 60), 'links' => $links];
                }
            }

            $clean[] = [
                'label' => mb_substr($label, 0, 60),
                'type' => $type,
                'url' => trim((string) ($item['url'] ?? '#')),
                'new_tab' => (bool) ($item['new_tab'] ?? false),
                'badge' => mb_substr(trim((string) ($item['badge'] ?? '')), 0, 30) ?: null,
                'view_all_mobile' => (bool) ($item['view_all_mobile'] ?? false),
                'children' => $children,
                'columns' => $columns,
            ];
        }

        return $clean;
    }
}
