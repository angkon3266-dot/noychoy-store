<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\Product;
use App\Support\CollectionRules;
use Illuminate\Database\Eloquent\Builder;

/**
 * Compiles a collection into a product query.
 *
 * Mirrors SegmentService (query / count / previewCount) so the two rule-driven
 * features in this codebase behave the same way; the rule *shape* differs
 * because product rules need repeated conditions on one field.
 */
class CollectionService
{
    /** @return Builder<Product> */
    public function query(Collection $collection): Builder
    {
        $pinned = $collection->products()->pluck('products.id')->all();

        if (! $collection->isSmart()) {
            // Manual: the picked list, nothing else. An empty manual collection
            // must return nothing rather than the whole catalogue.
            return Product::published()->whereIn('products.id', $pinned ?: [0]);
        }

        $rules = CollectionRules::sanitise((array) ($collection->rules ?? []));

        // A smart collection with no usable rules is a configuration mistake,
        // not "everything" — returning the full catalogue under a name like
        // "Eid Gifts" is worse than returning nothing, because it looks like it
        // worked. Pinned products still show, so a half-built collection can be
        // hand-seeded.
        if (empty($rules)) {
            return Product::published()->whereIn('products.id', $pinned ?: [0]);
        }

        return Product::published()->where(function (Builder $outer) use ($rules, $collection, $pinned) {
            $outer->where(fn (Builder $w) => $this->applyRules($w, $rules, $collection->match ?? 'all'));

            if ($pinned) {
                $outer->orWhereIn('products.id', $pinned);
            }
        });
    }

    /**
     * Apply a sanitised rule list to a query.
     * $match 'all' ANDs the conditions, 'any' ORs them.
     */
    public function applyRules(Builder $query, array $rules, string $match = 'all'): Builder
    {
        $any = $match === 'any';

        foreach ($rules as $rule) {
            $apply = fn (Builder $q) => $this->applyRule($q, $rule['field'], $rule['operator'], $rule['value']);

            $any ? $query->orWhere($apply) : $query->where($apply);
        }

        return $query;
    }

    /** Compile one condition. Unknown fields never reach here — see sanitise(). */
    protected function applyRule(Builder $q, string $field, string $operator, $value): void
    {
        match ($field) {
            'tag' => $this->applyTag($q, $operator, (string) $value),

            'title' => $this->applyText($q, 'products.name', $operator, (string) $value),
            'sku' => $this->applyText($q, 'products.sku', $operator, (string) $value),
            'colour' => $this->applyText($q, 'products.colors', $operator, (string) $value),
            'description' => $q->where(fn ($w) => $this->applyDescription($w, $operator, (string) $value)),

            'price' => $this->applyNumber($q, 'products.price', $operator, (float) $value),
            'compare_at_price' => $this->applyNumber($q, 'products.compare_at_price', $operator, (float) $value),
            'stock' => $this->applyNumber($q, 'products.stock_quantity', $operator, (float) $value),
            'weight' => $this->applyNumber($q, 'products.weight', $operator, (float) $value),

            'category' => $this->applyCategory($q, $operator, (string) $value),

            'in_stock' => $q->where('products.in_stock', $operator === 'is_true'),
            'featured' => $q->where('products.is_featured', $operator === 'is_true'),
            'bestseller' => $q->where('products.is_bestseller', $operator === 'is_true'),
            'preorder' => $q->where('products.is_preorder', $operator === 'is_true'),
            'has_variants' => $q->where('products.has_variants', $operator === 'is_true'),
            'on_sale' => $this->applyOnSale($q, $operator === 'is_true'),

            'created' => $operator === 'within_days'
                ? $q->where('products.created_at', '>=', now()->subDays(max(1, (int) $value)))
                : $q->where('products.created_at', '<', now()->subDays(max(1, (int) $value))),

            default => null,
        };
    }

    /**
     * `tags` is one comma-separated string, so a whole-tag match cannot be a
     * plain LIKE: the tag "gift" would also match "gift-card".
     *
     * Deliberately no raw SQL here — an earlier draft normalised the column
     * with `||`, which concatenates on SQLite (local) but is logical OR on
     * MySQL (production), so it would have quietly matched everything live.
     * These four patterns cover the four positions a tag can occupy, doubled
     * for the optional space after a comma.
     *
     * @return array<int, string>
     */
    protected function wholeTagPatterns(string $tag): array
    {
        $t = str_replace(['%', '_'], ['\%', '\_'], $tag);

        return [$t, $t.',%', $t.', %', '%,'.$t, '%, '.$t, '%,'.$t.',%', '%, '.$t.',%', '%,'.$t.', %', '%, '.$t.', %'];
    }

    protected function applyTag(Builder $q, string $operator, string $value): void
    {
        $needle = trim($value);
        $patterns = $this->wholeTagPatterns($needle);

        $whole = function ($w) use ($patterns) {
            foreach ($patterns as $p) {
                $w->orWhere('products.tags', 'like', $p);
            }
        };

        match ($operator) {
            'contains' => $q->where('products.tags', 'like', '%'.$needle.'%'),
            'not_contains' => $q->where(fn ($w) => $w->whereNull('products.tags')->orWhere('products.tags', 'not like', '%'.$needle.'%')),
            'is' => $q->where($whole),
            'is_not' => $q->whereNot($whole),
            default => null,
        };
    }

    protected function applyText(Builder $q, string $column, string $operator, string $value): void
    {
        match ($operator) {
            'contains' => $q->where($column, 'like', '%'.$value.'%'),
            'not_contains' => $q->where(fn ($w) => $w->whereNull($column)->orWhere($column, 'not like', '%'.$value.'%')),
            'is' => $q->where($column, $value),
            'is_not' => $q->where(fn ($w) => $w->whereNull($column)->orWhere($column, '!=', $value)),
            'starts_with' => $q->where($column, 'like', $value.'%'),
            'ends_with' => $q->where($column, 'like', '%'.$value),
            default => null,
        };
    }

    /** Description spans two columns — a shopper does not know which one it is in. */
    protected function applyDescription(Builder $q, string $operator, string $value): void
    {
        if ($operator === 'contains') {
            $q->where('products.description', 'like', '%'.$value.'%')
              ->orWhere('products.short_description', 'like', '%'.$value.'%');

            return;
        }

        $q->where(fn ($w) => $w->whereNull('products.description')->orWhere('products.description', 'not like', '%'.$value.'%'))
          ->where(fn ($w) => $w->whereNull('products.short_description')->orWhere('products.short_description', 'not like', '%'.$value.'%'));
    }

    protected function applyNumber(Builder $q, string $column, string $operator, float $value): void
    {
        match ($operator) {
            'gt' => $q->where($column, '>', $value),
            'gte' => $q->where($column, '>=', $value),
            'lt' => $q->where($column, '<', $value),
            'lte' => $q->where($column, '<=', $value),
            'is' => $q->where($column, $value),
            default => null,
        };
    }

    protected function applyOnSale(Builder $q, bool $onSale): void
    {
        $onSale
            ? $q->whereNotNull('products.compare_at_price')->whereColumn('products.compare_at_price', '>', 'products.price')
            : $q->where(fn ($w) => $w->whereNull('products.compare_at_price')->orWhereColumn('products.compare_at_price', '<=', 'products.price'));
    }

    /** Matches the primary category or any the product is also filed under. */
    protected function applyCategory(Builder $q, string $operator, string $value): void
    {
        $in = fn ($w) => $w->where('products.category_id', $value)
            ->orWhereHas('categories', fn ($c) => $c->where('categories.id', $value));

        $operator === 'is' ? $q->where($in) : $q->whereNot($in);
    }

    public function count(Collection $collection): int
    {
        return $this->query($collection)->count();
    }

    /** Live match count for the admin rule builder, before anything is saved. */
    public function previewCount(array $rules, string $match = 'all'): int
    {
        $rules = CollectionRules::sanitise($rules);

        if (empty($rules)) {
            return 0;
        }

        return $this->applyRules(Product::published(), $rules, $match)->count();
    }
}
