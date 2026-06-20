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
        $stored = Setting::get('menu', null);

        // Build the editor's starting tree: stored menu, or a default from categories.
        $items = is_array($stored) && ! empty($stored)
            ? $this->normalizeForEditor($stored)
            : $this->defaultTree();

        return view('admin.menu', [
            'items' => $items,
            'categories' => Category::orderBy('name')->get(['id', 'name', 'parent_id']),
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
        $menu = is_array($decoded) ? $this->sanitize($decoded) : [];
        Setting::put('menu', $menu);

        // Behaviour settings live alongside the rest of the theme.
        $theme = theme();
        $theme['menu_desktop_trigger'] = $data['menu_desktop_trigger'];
        $theme['menu_show_search'] = $request->boolean('menu_show_search');
        $theme['menu_cta_label'] = $data['menu_cta_label'] ?? null;
        $theme['menu_cta_link'] = $data['menu_cta_link'] ?? null;
        Setting::put('theme', $theme);

        return back()->with('success', 'Menu saved.');
    }

    /** Recursively clean posted items down to a safe 2-level structure. */
    protected function sanitize(array $items, int $depth = 0): array
    {
        $clean = [];
        foreach ($items as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            $type = in_array($item['type'] ?? 'link', ['link', 'category'], true) ? $item['type'] : 'link';
            $value = $item['value'] ?? '';
            if ($type === 'category') {
                $value = (int) $value;
                if ($value <= 0) {
                    continue;
                }
            } else {
                $value = trim((string) $value);
            }
            if ($label === '' && $type === 'link') {
                continue;
            }

            $row = [
                'label' => mb_substr($label, 0, 60),
                'type' => $type,
                'value' => $value,
                'new_tab' => (bool) ($item['new_tab'] ?? false),
                'children' => $depth === 0 && ! empty($item['children']) ? $this->sanitize($item['children'], 1) : [],
            ];
            $clean[] = $row;
        }

        return $clean;
    }

    /** Stored menu → editor shape (labels resolved for category items). */
    protected function normalizeForEditor(array $items): array
    {
        $cats = Category::all()->keyBy('id');

        $map = function (array $item) use (&$map, $cats) {
            $label = $item['label'] ?? '';
            if (($item['type'] ?? '') === 'category' && $label === '') {
                $label = $cats->get((int) ($item['value'] ?? 0))?->name ?? '';
            }

            return [
                'label' => $label,
                'type' => $item['type'] ?? 'link',
                'value' => $item['value'] ?? '',
                'new_tab' => (bool) ($item['new_tab'] ?? false),
                'children' => array_map($map, $item['children'] ?? []),
            ];
        };

        return array_map($map, $items);
    }

    protected function defaultTree(): array
    {
        $cats = Category::orderBy('position')->get();
        $tree = [['label' => 'Shop All', 'type' => 'link', 'value' => '/shop', 'new_tab' => false, 'children' => []]];
        foreach ($cats->whereNull('parent_id') as $cat) {
            $children = $cats->where('parent_id', $cat->id)
                ->map(fn ($c) => ['label' => $c->name, 'type' => 'category', 'value' => $c->id, 'new_tab' => false, 'children' => []])
                ->values()->all();
            $tree[] = ['label' => $cat->name, 'type' => 'category', 'value' => $cat->id, 'new_tab' => false, 'children' => $children];
        }

        return $tree;
    }
}
