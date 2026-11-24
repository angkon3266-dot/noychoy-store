<?php

namespace App\Mcp\Tools\Catalog;

use App\Mcp\Tools\StoreTool;
use App\Models\Category;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListCategories extends StoreTool
{
    protected ?string $section = 'categories';

    protected string $name = 'list_categories';

    protected string $description = 'All product categories with id, name, slug, parent, active flag, product count, SEO fields and storefront URL. Use before create_product or to pick a category for a menu link.';

    protected function run(Request $request): Response
    {
        return $this->json(['categories' => Category::query()->withCount('products')
            ->orderBy('position')->orderBy('name')->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'parent_id' => $c->parent_id,
                'is_active' => (bool) $c->is_active,
                'position' => (int) $c->position,
                'products' => (int) $c->products_count,
                'description' => $c->description,
                'meta_title' => $c->meta_title,
                'meta_description' => $c->meta_description,
                'url' => route('category.show', $c->slug),
            ])->all()]);
    }
}
