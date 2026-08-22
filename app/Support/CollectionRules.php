<?php

namespace App\Support;

/**
 * The rule vocabulary a smart collection can be built from.
 *
 * ONE source of truth: the admin rule builder renders its dropdowns from here,
 * CollectionService compiles queries from here, and the API validates against
 * here. Adding a rule means adding it in this file and nowhere else.
 *
 * Shape mirrors Shopify's smart collections — a list of
 * {field, operator, value} conditions plus a match mode (all / any) — rather
 * than CustomerSegment's flat keyed array, because product rules genuinely
 * need the same field more than once ("tag is eid AND tag is gift", or
 * "price >= 1000 AND price <= 2500").
 */
class CollectionRules
{
    /** Operators, grouped by the value type they work on. */
    public const TEXT_OPS = ['contains', 'not_contains', 'is', 'is_not', 'starts_with', 'ends_with'];

    public const NUMBER_OPS = ['gt', 'gte', 'lt', 'lte', 'is'];

    public const BOOL_OPS = ['is_true', 'is_false'];

    public const DATE_OPS = ['within_days', 'before_days'];

    public const SELECT_OPS = ['is', 'is_not'];

    /** Human labels for every operator, for the admin dropdown. */
    public static function operatorLabels(): array
    {
        return [
            'contains' => 'contains',
            'not_contains' => 'does not contain',
            'is' => 'is',
            'is_not' => 'is not',
            'starts_with' => 'starts with',
            'ends_with' => 'ends with',
            'gt' => 'is greater than',
            'gte' => 'is at least',
            'lt' => 'is less than',
            'lte' => 'is at most',
            'is_true' => 'is yes',
            'is_false' => 'is no',
            'within_days' => 'added in the last (days)',
            'before_days' => 'added more than (days) ago',
        ];
    }

    /**
     * Every field a rule can target.
     *
     * type drives the admin input: text | number | boolean | select | date.
     * Only fields listed here are accepted — an unknown field is dropped rather
     * than silently matching everything.
     */
    public static function fields(): array
    {
        return [
            'tag' => ['label' => 'Tag', 'type' => 'text', 'operators' => ['contains', 'not_contains', 'is', 'is_not'], 'hint' => 'Matches one of the product\'s comma-separated tags.'],
            'title' => ['label' => 'Product title', 'type' => 'text', 'operators' => self::TEXT_OPS],
            'sku' => ['label' => 'SKU', 'type' => 'text', 'operators' => ['contains', 'is', 'starts_with']],
            'description' => ['label' => 'Description', 'type' => 'text', 'operators' => ['contains', 'not_contains']],
            'colour' => ['label' => 'Colour', 'type' => 'text', 'operators' => ['contains', 'not_contains']],
            'price' => ['label' => 'Price', 'type' => 'number', 'operators' => self::NUMBER_OPS],
            'compare_at_price' => ['label' => 'Compare-at price', 'type' => 'number', 'operators' => self::NUMBER_OPS],
            'stock' => ['label' => 'Stock quantity', 'type' => 'number', 'operators' => self::NUMBER_OPS],
            'weight' => ['label' => 'Weight', 'type' => 'number', 'operators' => self::NUMBER_OPS],
            'category' => ['label' => 'Category', 'type' => 'select', 'operators' => self::SELECT_OPS, 'hint' => 'Matches the primary category or any it is also filed under.'],
            'in_stock' => ['label' => 'In stock', 'type' => 'boolean', 'operators' => self::BOOL_OPS],
            'on_sale' => ['label' => 'On sale', 'type' => 'boolean', 'operators' => self::BOOL_OPS, 'hint' => 'Compare-at price is set and higher than the price.'],
            'featured' => ['label' => 'Featured', 'type' => 'boolean', 'operators' => self::BOOL_OPS],
            'bestseller' => ['label' => 'Bestseller flag', 'type' => 'boolean', 'operators' => self::BOOL_OPS],
            'preorder' => ['label' => 'Pre-order', 'type' => 'boolean', 'operators' => self::BOOL_OPS],
            'has_variants' => ['label' => 'Has options', 'type' => 'boolean', 'operators' => self::BOOL_OPS],
            'created' => ['label' => 'Date added', 'type' => 'date', 'operators' => self::DATE_OPS],
        ];
    }

    public static function fieldKeys(): array
    {
        return array_keys(self::fields());
    }

    /** Every operator that is valid for at least one field. */
    public static function allOperators(): array
    {
        return array_keys(self::operatorLabels());
    }

    /** Is this field/operator pair one we can actually compile? */
    public static function accepts(string $field, string $operator): bool
    {
        $fields = self::fields();

        return isset($fields[$field]) && in_array($operator, $fields[$field]['operators'], true);
    }

    /** Does this field/operator pair need a value from the admin? */
    public static function needsValue(string $operator): bool
    {
        return ! in_array($operator, self::BOOL_OPS, true);
    }

    /** Drop anything malformed so a bad row can never widen the match. */
    public static function sanitise(array $rules): array
    {
        $clean = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $field = (string) ($rule['field'] ?? '');
            $operator = (string) ($rule['operator'] ?? '');

            if (! self::accepts($field, $operator)) {
                continue;
            }

            $value = $rule['value'] ?? null;
            if (self::needsValue($operator) && (is_null($value) || trim((string) $value) === '')) {
                continue;
            }

            $clean[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => self::needsValue($operator) ? trim((string) $value) : null,
            ];
        }

        return $clean;
    }
}
