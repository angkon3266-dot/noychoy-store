<?php

namespace App\Mcp\Tools\Catalog;

use App\Http\Controllers\Admin\CollectionController;
use App\Mcp\Support\AdminAction;
use App\Models\Collection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class CreateCollection extends CollectionTool
{
    protected string $name = 'create_collection';

    protected string $description = 'Create a collection. Smart (type=smart): products are picked automatically by rules, e.g. [{"field":"tag","operator":"contains","value":"gift"},{"field":"price","operator":"lte","value":"2000"}]. Manual (type=manual): pass products in display order. Active by default.';

    public function schema(JsonSchema $schema): array
    {
        $fields = self::fields($schema);
        $fields['name'] = $fields['name']->required();

        return $fields;
    }

    protected function run(Request $request): Response
    {
        $input = self::input($request->all(), ['type' => 'smart', 'match' => 'all', 'sort' => 'new', 'is_active' => 1]);
        $message = AdminAction::run(CollectionController::class, 'store', $input);

        $collection = Collection::where('name', $input['name'])->latest('id')->first();

        return $this->json(['message' => $message, 'collection' => $collection ? self::present($collection) : null]);
    }
}
