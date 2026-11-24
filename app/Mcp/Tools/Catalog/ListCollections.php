<?php

namespace App\Mcp\Tools\Catalog;

use App\Models\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListCollections extends CollectionTool
{
    protected string $name = 'list_collections';

    protected string $description = 'All collections (curated product groups like "Gifts under 2000"): rules or pinned products, sort, active flag, menu flag and storefront URL.';

    protected function run(Request $request): Response
    {
        return $this->json(['collections' => Collection::orderBy('position')->orderBy('name')->get()
            ->map(fn (Collection $c) => self::present($c))->all()]);
    }
}
