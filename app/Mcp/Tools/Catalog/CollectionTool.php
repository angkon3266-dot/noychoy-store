<?php

namespace App\Mcp\Tools\Catalog;

use App\Mcp\ToolError;
use App\Mcp\Tools\StoreTool;
use App\Models\Collection;
use App\Models\Product;
use App\Support\CollectionRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;

abstract class CollectionTool extends StoreTool
{
    protected ?string $section = 'collections';

    protected static function fields(JsonSchema $schema): array
    {
        $ruleFields = collect(CollectionRules::fields())
            ->map(fn ($f, $key) => $key.' ('.implode('/', $f['operators']).')')->implode(', ');

        return [
            'name' => $schema->string(),
            'description' => $schema->string(),
            'type' => $schema->string()->enum(['smart', 'manual'])->description('smart = products picked by rules; manual = the products you list.'),
            'match' => $schema->string()->enum(['all', 'any'])->description('Smart: product must match all rules, or any one.'),
            'rules' => $schema->array()->description('Smart collection rules. Fields and operators: '.$ruleFields.'. For category the value is the category id. Boolean operators take no value.')
                ->items($schema->object([
                    'field' => $schema->string()->required(),
                    'operator' => $schema->string()->required(),
                    'value' => $schema->string(),
                ])),
            'products' => $schema->array()->items($schema->string())->description('Manual collection: product ids, slugs or SKUs, in display order.'),
            'sort' => $schema->string()->enum(['new', 'price_asc', 'price_desc', 'name', 'popular', 'best_selling']),
            'is_active' => $schema->boolean(),
            'show_in_menu' => $schema->boolean()->description('Offer it in the menu builder (it still has to be added with update_menu).'),
            'meta_title' => $schema->string(),
            'meta_description' => $schema->string(),
        ];
    }

    /** Agent arguments → the admin form's fields. */
    protected static function input(array $args, array $defaults): array
    {
        if (array_key_exists('products', $args)) {
            $args['product_ids'] = collect((array) $args['products'])->map(function ($ref) {
                $id = Product::query()
                    ->when(is_numeric($ref), fn ($q) => $q->where('id', (int) $ref), fn ($q) => $q->where('slug', $ref)->orWhere('sku', $ref))
                    ->value('id');

                return $id ?? throw new ToolError("No product matches \"{$ref}\". Try search_products.");
            })->all();
        }
        if (isset($args['rules']) && is_array($args['rules'])) {
            $args['rules'] = array_values(array_map(fn ($r) => [
                'field' => $r['field'] ?? null,
                'operator' => $r['operator'] ?? null,
                'value' => isset($r['value']) ? (string) $r['value'] : null,
            ], array_filter($args['rules'], 'is_array')));
        }
        foreach (['is_active', 'show_in_menu'] as $flag) {
            if (array_key_exists($flag, $args)) {
                $args[$flag] = filter_var($args[$flag], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        unset($args['products'], $args['collection'], $args['image']);

        return array_merge($defaults, $args);
    }

    protected static function present(Collection $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'type' => $c->type,
            'match' => $c->match,
            'rules' => $c->rules ?: [],
            'pinned_product_ids' => $c->products()->pluck('products.id')->all(),
            'sort' => $c->sort,
            'is_active' => (bool) $c->is_active,
            'show_in_menu' => (bool) $c->show_in_menu,
            'description' => $c->description,
            'meta_title' => $c->meta_title,
            'meta_description' => $c->meta_description,
            'url' => route('collection.show', $c->slug),
        ];
    }
}
