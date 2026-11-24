<?php

namespace App\Mcp\Tools\Catalog;

use App\Http\Controllers\Admin\CollectionController;
use App\Mcp\Support\AdminAction;
use App\Mcp\ToolError;
use App\Models\Collection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateCollection extends CollectionTool
{
    protected string $name = 'update_collection';

    protected string $description = 'Change a collection. Only the fields you pass change; rules and products, when passed, REPLACE the current list (send the full list). The slug (URL) never changes.';

    public function schema(JsonSchema $schema): array
    {
        return ['collection' => $schema->string()->description('Collection id, slug or exact name.')->required()] + self::fields($schema);
    }

    protected function run(Request $request): Response
    {
        $ref = (string) $this->need($request, 'collection');
        $collection = Collection::query()
            ->when(is_numeric($ref), fn ($q) => $q->where('id', (int) $ref), fn ($q) => $q->where('slug', $ref)->orWhere('name', $ref))
            ->first() ?? throw new ToolError("No collection matches \"{$ref}\". Call list_collections.");

        $current = [
            'name' => $collection->name,
            'description' => $collection->description,
            'type' => $collection->type,
            'match' => $collection->match,
            'sort' => $collection->sort,
            'position' => $collection->position,
            'is_active' => $collection->is_active ? 1 : 0,
            'show_in_menu' => $collection->show_in_menu ? 1 : 0,
            'meta_title' => $collection->meta_title,
            'meta_description' => $collection->meta_description,
            'rules' => $collection->rules ?: [],
            'product_ids' => $collection->products()->pluck('products.id')->all(),
        ];

        $message = AdminAction::run(CollectionController::class, 'update', self::input($request->all(), $current), ['collection' => $collection]);

        return $this->json(['message' => $message, 'collection' => self::present($collection->fresh())]);
    }
}
