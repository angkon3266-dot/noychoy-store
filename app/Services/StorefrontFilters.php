<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Builds and applies the storefront filter sidebar. Which attributes / custom
 * fields appear is chosen by the admin (Appearance → Storefront filters);
 * the available values are derived from published products.
 */
class StorefrontFilters
{
    public function config(): array
    {
        $saved = Setting::get('storefront_filters', []);
        $saved = is_array($saved) ? $saved : [];

        return array_merge([
            'attributes' => [],        // variation attribute names shown as filters
            'custom_fields' => [],     // custom field labels shown as filters
            'tags' => false,
            'price' => true,
            'in_stock' => true,
            'on_sale' => true,
            'price_ranges' => [[100, 300], [301, 500], [501, 1000], [1001, 2500], [2501, 5000], [5001, 10000]],
        ], $saved);
    }

    /** Distinct variation-attribute names across published products (for the admin picker). */
    public function discoverAttributes(): Collection
    {
        return Product::published()->whereNotNull('options')->pluck('options')
            ->flatMap(fn ($opts) => collect($opts)->pluck('name'))
            ->map(fn ($n) => trim((string) $n))->filter()->unique()->sort()->values();
    }

    /** Distinct custom-field labels across published products (for the admin picker). */
    public function discoverCustomFields(): Collection
    {
        return Product::published()->get(['custom_label', 'custom_fields'])
            ->flatMap(function ($p) {
                return collect($p->customFieldList())->pluck('label');
            })
            ->map(fn ($l) => trim((string) $l))->filter()->unique()->sort()->values();
    }

    /** Apply the active request filters to a product query. */
    public function apply(Builder $query, Request $request): void
    {
        // Price band(s) — checkbox ranges like "301-500".
        $ranges = array_filter((array) $request->query('price_range', []));
        if ($ranges) {
            $query->where(function ($w) use ($ranges) {
                foreach ($ranges as $r) {
                    [$min, $max] = array_pad(explode('-', $r), 2, null);
                    if (is_numeric($min) && is_numeric($max)) {
                        $w->orWhereBetween('price', [(float) $min, (float) $max]);
                    }
                }
            });
        }

        // Free min/max price.
        $query->when($request->filled('price_min'), fn ($q) => $q->where('price', '>=', (float) $request->query('price_min')))
              ->when($request->filled('price_max'), fn ($q) => $q->where('price', '<=', (float) $request->query('price_max')))
              ->when($request->boolean('in_stock'), fn ($q) => $q->where('in_stock', true))
              ->when($request->boolean('on_sale'), fn ($q) => $q->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'price'));

        // Variation attribute filters: attr[Color][]=Blue
        foreach ((array) $request->query('attr', []) as $name => $values) {
            $values = array_filter((array) $values);
            if (empty($values)) {
                continue;
            }
            $query->whereHas('variants', function ($q) use ($name, $values) {
                $q->where(function ($w) use ($name, $values) {
                    foreach ($values as $v) {
                        $w->orWhere('attributes', 'like', '%"'.$name.'":"'.$v.'"%');
                    }
                });
            });
        }

        // Custom field filters: cf[Material][]=Leather
        foreach ((array) $request->query('cf', []) as $label => $values) {
            $values = array_filter((array) $values);
            if (empty($values)) {
                continue;
            }
            $query->where(function ($w) use ($values) {
                foreach ($values as $v) {
                    $w->orWhere('custom_value', $v)
                      ->orWhere('custom_fields', 'like', '%"value":"'.$v.'"%');
                }
            });
        }

        // Tag filters: tags[]=bestseller
        $tags = array_filter((array) $request->query('tags', []));
        if ($tags) {
            $query->where(function ($w) use ($tags) {
                foreach ($tags as $t) {
                    $w->orWhere('tags', 'like', '%'.$t.'%');
                }
            });
        }
    }

    /**
     * Build display groups for the sidebar from the chosen config + product data.
     * $scope is a query of the products being browsed (e.g. a category) used to
     * derive available values; falls back to all published products.
     */
    public function groups(Request $request, ?Builder $scope = null): array
    {
        $cfg = $this->config();
        $scope ??= Product::published();
        $products = (clone $scope)->get(['id', 'options', 'tags', 'custom_label', 'custom_value', 'custom_fields', 'price', 'compare_at_price']);

        $groups = [];

        // Attribute groups
        foreach ($cfg['attributes'] as $name) {
            $values = $products->flatMap(function ($p) use ($name) {
                return collect($p->options ?? [])->firstWhere('name', $name)['values'] ?? [];
            })->map(fn ($v) => trim((string) $v))->filter()->unique()->sort()->values();
            if ($values->isEmpty()) {
                continue;
            }
            $selected = array_filter((array) ($request->query('attr', [])[$name] ?? []));
            $isColor = str_contains(strtolower($name), 'color') || str_contains(strtolower($name), 'colour');
            $groups[] = [
                'type' => 'attribute',
                'label' => $name,
                'param' => "attr[{$name}][]",
                'is_color' => $isColor,
                'options' => $values->map(fn ($v) => [
                    'value' => $v, 'label' => $v,
                    'hex' => $isColor ? color_hex($v) : null,
                    'checked' => in_array($v, $selected, true),
                ])->all(),
            ];
        }

        // Price ranges
        if ($cfg['price'] && ! empty($cfg['price_ranges'])) {
            $sel = array_filter((array) $request->query('price_range', []));
            $groups[] = [
                'type' => 'checkbox', 'label' => 'Price', 'param' => 'price_range[]',
                'options' => collect($cfg['price_ranges'])->map(function ($r) use ($sel) {
                    $val = $r[0].'-'.$r[1];
                    return ['value' => $val, 'label' => $r[0].' To '.$r[1], 'hex' => null, 'checked' => in_array($val, $sel, true)];
                })->all(),
            ];
        }

        // Tags
        if ($cfg['tags']) {
            $tags = $products->flatMap(fn ($p) => collect(explode(',', (string) $p->tags))->map(fn ($t) => trim($t)))
                ->filter()->unique()->sort()->values();
            if ($tags->isNotEmpty()) {
                $sel = array_filter((array) $request->query('tags', []));
                $groups[] = [
                    'type' => 'checkbox', 'label' => 'Tags', 'param' => 'tags[]',
                    'options' => $tags->map(fn ($t) => ['value' => $t, 'label' => $t, 'hex' => null, 'checked' => in_array($t, $sel, true)])->all(),
                ];
            }
        }

        // Custom fields
        foreach ($cfg['custom_fields'] as $label) {
            $values = $products->flatMap(fn ($p) => collect($p->customFieldList())->where('label', $label)->pluck('value'))
                ->map(fn ($v) => trim((string) $v))->filter()->unique()->sort()->values();
            if ($values->isEmpty()) {
                continue;
            }
            $sel = array_filter((array) ($request->query('cf', [])[$label] ?? []));
            $groups[] = [
                'type' => 'checkbox', 'label' => $label, 'param' => "cf[{$label}][]",
                'options' => $values->map(fn ($v) => ['value' => $v, 'label' => $v, 'hex' => null, 'checked' => in_array($v, $sel, true)])->all(),
            ];
        }

        return $groups;
    }
}
