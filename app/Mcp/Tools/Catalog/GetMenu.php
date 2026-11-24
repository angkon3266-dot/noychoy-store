<?php

namespace App\Mcp\Tools\Catalog;

use App\Mcp\Tools\StoreTool;
use App\Models\Category;
use App\Models\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetMenu extends StoreTool
{
    protected ?string $section = 'menu';

    protected string $name = 'get_menu';

    protected string $description = 'The storefront\'s main navigation menu (Admin → Menu) exactly as stored: each top-level item with its type (link, dropdown or mega), URL, children or mega-menu columns. Also lists the category and collection URLs you can link to. Read this before update_menu.';

    protected function run(Request $request): Response
    {
        $theme = theme();

        return $this->json([
            'items' => site_menu(),
            'settings' => [
                'menu_desktop_trigger' => $theme['menu_desktop_trigger'] ?? 'hover',
                'menu_show_search' => (bool) ($theme['menu_show_search'] ?? false),
                'menu_cta_label' => $theme['menu_cta_label'] ?? null,
                'menu_cta_link' => $theme['menu_cta_link'] ?? null,
            ],
            'link_targets' => [
                'categories' => Category::where('is_active', true)->orderBy('name')->get(['name', 'slug'])
                    ->map(fn ($c) => ['name' => $c->name, 'url' => route('category.show', $c->slug)])->all(),
                'collections' => Collection::orderBy('name')->get(['id', 'name', 'slug'])
                    ->map(fn ($c) => ['collection_id' => $c->id, 'name' => $c->name, 'url' => route('collection.show', $c->slug)])->all(),
                'shop_all' => url('/shop'),
            ],
        ]);
    }
}
