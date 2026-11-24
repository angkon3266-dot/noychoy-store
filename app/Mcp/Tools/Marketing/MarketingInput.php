<?php

namespace App\Mcp\Tools\Marketing;

use App\Mcp\ToolError;
use App\Models\Category;
use App\Models\Product;

/** Agent-friendly references (slugs, names, SKUs) → the ids the admin forms post. */
trait MarketingInput
{
    protected static function scopeIds(array $args): array
    {
        if (isset($args['categories'])) {
            $args['category_ids'] = collect((array) $args['categories'])->map(fn ($ref) => Category::query()
                ->when(is_numeric($ref), fn ($q) => $q->where('id', (int) $ref), fn ($q) => $q->where('slug', $ref)->orWhere('name', $ref))
                ->value('id') ?? throw new ToolError("No category matches \"{$ref}\". Call list_categories."))->all();
        }
        if (isset($args['products'])) {
            $args['product_ids'] = collect((array) $args['products'])->map(fn ($ref) => Product::query()
                ->when(is_numeric($ref), fn ($q) => $q->where('id', (int) $ref), fn ($q) => $q->where('slug', $ref)->orWhere('sku', $ref))
                ->value('id') ?? throw new ToolError("No product matches \"{$ref}\". Try search_products."))->all();
        }
        unset($args['categories'], $args['products'], $args['confirm']);

        return $args;
    }

    /** Checkbox fields: the admin forms read them with $request->boolean(). */
    protected static function flags(array $args, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $args)) {
                $args[$key] = filter_var($args[$key], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }

        return $args;
    }
}
