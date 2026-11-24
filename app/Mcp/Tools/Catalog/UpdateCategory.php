<?php

namespace App\Mcp\Tools\Catalog;

use App\Http\Controllers\Admin\CategoryController;
use App\Mcp\Support\AdminAction;
use App\Mcp\ToolError;
use App\Mcp\Tools\StoreTool;
use App\Models\Category;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateCategory extends StoreTool
{
    protected ?string $section = 'categories';

    protected string $name = 'update_category';

    protected string $description = 'Change a category: name, parent, description, active flag (is_active=false hides it from the storefront), sort position or SEO fields. Only the fields you pass change. The slug (URL) never changes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->description('Category id, slug or exact name.')->required(),
            'name' => $schema->string(),
            'parent' => $schema->string()->description('Parent category id, slug or name; empty string = top level.'),
            'description' => $schema->string(),
            'is_active' => $schema->boolean(),
            'position' => $schema->integer(),
            'meta_title' => $schema->string(),
            'meta_description' => $schema->string(),
        ];
    }

    protected function run(Request $request): Response
    {
        $ref = (string) $this->need($request, 'category');
        $category = Category::query()
            ->when(is_numeric($ref), fn ($q) => $q->where('id', (int) $ref), fn ($q) => $q->where('slug', $ref)->orWhere('name', $ref))
            ->first() ?? throw new ToolError("No category matches \"{$ref}\". Call list_categories.");

        // The admin form always posts every field; start from what is saved.
        $current = [
            'name' => $category->name,
            'parent_id' => $category->parent_id,
            'description' => $category->description,
            'position' => $category->position,
            'is_active' => $category->is_active ? 1 : 0,
            'is_preorder' => $category->is_preorder ? 1 : 0,
            'google_category' => $category->google_category,
            'meta_title' => $category->meta_title,
            'meta_description' => $category->meta_description,
        ];

        $message = AdminAction::run(CategoryController::class, 'update', CreateCategory::input($request->all(), $current), ['category' => $category]);
        $category->refresh();

        return $this->json(['message' => $message, 'category' => $category->only(['id', 'name', 'slug', 'parent_id', 'is_active', 'position', 'description', 'meta_title', 'meta_description'])]);
    }
}
