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

/** Saved through the admin CategoryController, so its validation applies. */
class CreateCategory extends StoreTool
{
    protected ?string $section = 'categories';

    protected string $name = 'create_category';

    protected string $description = 'Create a product category (visible on the storefront when is_active, default true). Use parent to nest it under another category.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'parent' => $schema->string()->description('Parent category id, slug or exact name.'),
            'description' => $schema->string()->description('Up to 500 characters.'),
            'is_active' => $schema->boolean(),
            'position' => $schema->integer()->description('Sort order among its siblings (0 first).'),
            'meta_title' => $schema->string(),
            'meta_description' => $schema->string(),
        ];
    }

    protected function run(Request $request): Response
    {
        $input = self::input($request->all(), ['is_active' => 1, 'parent_id' => null, 'google_category' => null]);
        $message = AdminAction::run(CategoryController::class, 'store', $input);

        $category = Category::where('name', $input['name'])->latest('id')->first();

        return $this->json(['message' => $message, 'category' => $category?->only(['id', 'name', 'slug', 'parent_id', 'is_active'])]);
    }

    /** Agent arguments → the admin form's fields. */
    public static function input(array $args, array $defaults = []): array
    {
        if (array_key_exists('parent', $args)) {
            $ref = $args['parent'];
            $args['parent_id'] = blank($ref) ? null : (Category::query()
                ->when(is_numeric($ref), fn ($q) => $q->where('id', (int) $ref), fn ($q) => $q->where('slug', $ref)->orWhere('name', $ref))
                ->value('id') ?? throw new ToolError("No category matches parent \"{$ref}\". Call list_categories."));
        }
        unset($args['parent'], $args['category'], $args['image']);

        foreach (['is_active', 'is_preorder'] as $flag) {
            if (array_key_exists($flag, $args)) {
                $args[$flag] = filter_var($args[$flag], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }

        return array_merge($defaults, $args);
    }
}
