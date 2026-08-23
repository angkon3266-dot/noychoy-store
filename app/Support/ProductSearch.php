<?php

namespace App\Support;

use App\Models\Product;
use App\Services\StorefrontFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Storefront product search.
 *
 * The old version put the entire query into one LIKE '%…%', which meant a
 * shopper typing two words got nothing at all: "gold ring" only matched a
 * record containing that exact string, so "Aurora Ring — Gold Plated" was
 * invisible. Nobody types one word.
 *
 * This splits the query into words and requires every word to appear
 * somewhere — name, tags, SKU, description or category — then orders by how
 * well each product matches rather than by how recently it was added. If the
 * strict search finds nothing it loosens to "any word", and if that still
 * finds nothing it offers the closest spelling it can find, so a typo is a
 * suggestion rather than a dead end.
 *
 * Deliberately no search engine: this is a ~100 product catalogue on shared
 * hosting with no Node runtime. Meilisearch or Scout would be a second service
 * to run and keep in sync for a result set that fits in one page.
 */
class ProductSearch
{
    /** Words too common to narrow anything down. */
    private const NOISE = ['the', 'a', 'an', 'and', 'or', 'for', 'with', 'of', 'in', 'to'];

    /** Longest query we will tokenise — beyond this it is not a search. */
    private const MAX_TOKENS = 6;

    /**
     * Split a raw query into meaningful words.
     *
     * @return array<int, string>
     */
    public static function tokens(?string $term): array
    {
        $term = trim(mb_strtolower((string) $term));

        if ($term === '') {
            return [];
        }

        $words = preg_split('/[\s,]+/u', $term) ?: [];

        $words = array_values(array_filter($words, function ($w) {
            // One-character words match nearly everything; noise words match
            // nearly everything too. Both make the AND useless.
            return mb_strlen($w) > 1 && ! in_array($w, self::NOISE, true);
        }));

        // If the query was ALL noise ("the a"), fall back to the raw string so
        // the shopper still gets something rather than the whole catalogue.
        if (empty($words) && $term !== '') {
            $words = [$term];
        }

        return array_slice(array_unique($words), 0, self::MAX_TOKENS);
    }

    /**
     * Constrain a query to products matching the term.
     *
     * @param  bool  $all  true = every word must match (precise), false = any word (broad)
     */
    public static function apply(Builder $query, ?string $term, bool $all = true): Builder
    {
        $tokens = static::tokens($term);

        if (empty($tokens)) {
            return $query;
        }

        return $query->where(function ($outer) use ($tokens, $all) {
            foreach ($tokens as $i => $token) {
                $match = fn ($q) => $q
                    ->where('name', 'like', "%{$token}%")
                    ->orWhere('sku', 'like', "%{$token}%")
                    ->orWhere('short_description', 'like', "%{$token}%")
                    ->orWhere('tags', 'like', "%{$token}%")
                    ->orWhere('description', 'like', "%{$token}%")
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$token}%"));

                // AND between words, OR within a word's fields.
                if ($all || $i === 0) {
                    $outer->where($match);
                } else {
                    $outer->orWhere($match);
                }
            }
        });
    }

    /**
     * Order by how well each row matches, best first.
     *
     * A shopper searching "ring" wants rings, not whatever was added most
     * recently — which is what they got, because search results fell through to
     * the default "newest" sort.
     */
    public static function orderByRelevance(Builder $query, ?string $term): Builder
    {
        $tokens = static::tokens($term);

        if (empty($tokens)) {
            return $query;
        }

        $phrase = trim(mb_strtolower((string) $term));
        $cases = [];
        $bindings = [];

        // Whole phrase first — the strongest signal there is.
        $cases[] = 'CASE WHEN LOWER(products.name) = ? THEN 200 ELSE 0 END';
        $bindings[] = $phrase;

        $cases[] = 'CASE WHEN LOWER(products.name) LIKE ? THEN 120 ELSE 0 END';
        $bindings[] = $phrase.'%';

        $cases[] = 'CASE WHEN LOWER(products.name) LIKE ? THEN 80 ELSE 0 END';
        $bindings[] = '%'.$phrase.'%';

        $cases[] = 'CASE WHEN LOWER(products.sku) = ? THEN 150 ELSE 0 END';
        $bindings[] = $phrase;

        // Then each word, weighted by which field it turned up in.
        foreach ($tokens as $token) {
            $cases[] = 'CASE WHEN LOWER(products.name) LIKE ? THEN 30 ELSE 0 END';
            $bindings[] = '%'.$token.'%';

            $cases[] = 'CASE WHEN LOWER(products.tags) LIKE ? THEN 12 ELSE 0 END';
            $bindings[] = '%'.$token.'%';

            $cases[] = 'CASE WHEN LOWER(products.short_description) LIKE ? THEN 6 ELSE 0 END';
            $bindings[] = '%'.$token.'%';
        }

        return $query->orderByRaw('('.implode(' + ', $cases).') DESC', $bindings);
    }

    /**
     * The closest thing in the catalogue to what they typed.
     *
     * Compared in PHP against a cached vocabulary of product names, tags and
     * category names — small enough to be cheap, and the only way to catch a
     * misspelling without a search engine. Returns null when nothing is close
     * enough to be worth suggesting, because a bad guess is worse than none.
     */
    public static function didYouMean(?string $term): ?string
    {
        $tokens = static::tokens($term);

        if (empty($tokens)) {
            return null;
        }

        $vocabulary = static::vocabulary();

        if (empty($vocabulary)) {
            return null;
        }

        $suggested = [];
        $changed = false;

        foreach ($tokens as $token) {
            // Already a real word in the catalogue — leave it alone.
            if (in_array($token, $vocabulary, true)) {
                $suggested[] = $token;

                continue;
            }

            $best = null;
            $bestDistance = PHP_INT_MAX;

            foreach ($vocabulary as $word) {
                // Only compare words of a similar length; "ring" and
                // "necklaces" are never a typo for each other.
                if (abs(mb_strlen($word) - mb_strlen($token)) > 2) {
                    continue;
                }

                $distance = levenshtein($token, $word);

                // Levenshtein charges 2 for a transposition, but two swapped
                // letters is the single most common typing mistake there is —
                // "rign" for "ring". If the words are the same letters in a
                // different order, count it as the one slip it actually was.
                if ($distance === 2 && static::isTransposition($token, $word)) {
                    $distance = 1;
                }

                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $word;
                }
            }

            // One edit for a short word, two for a longer one. Anything looser
            // starts "correcting" words the shopper meant.
            $allowed = mb_strlen($token) <= 4 ? 1 : 2;

            if ($best !== null && $bestDistance <= $allowed) {
                $suggested[] = $best;
                $changed = true;
            } else {
                $suggested[] = $token;
            }
        }

        return $changed ? implode(' ', $suggested) : null;
    }

    /** Same letters, different order — i.e. the shopper's fingers crossed over. */
    protected static function isTransposition(string $a, string $b): bool
    {
        if (mb_strlen($a) !== mb_strlen($b)) {
            return false;
        }

        $x = mb_str_split($a);
        $y = mb_str_split($b);
        sort($x);
        sort($y);

        return $x === $y;
    }

    /**
     * Every word worth matching against, from the live catalogue.
     *
     * @return array<int, string>
     */
    public static function vocabulary(): array
    {
        return Cache::remember('search.vocabulary.'.StorefrontFilters::version(), 3600, function () {
            $words = [];

            Product::published()
                ->select(['name', 'tags'])
                ->chunk(500, function ($rows) use (&$words) {
                    foreach ($rows as $row) {
                        foreach (preg_split('/[\s,]+/u', mb_strtolower($row->name.' '.$row->tags)) ?: [] as $w) {
                            $w = trim($w, " \t\n\r\0\x0B-–—…,.");
                            if (mb_strlen($w) > 2) {
                                $words[$w] = true;
                            }
                        }
                    }
                });

            foreach (\App\Models\Category::where('is_active', true)->pluck('name') as $name) {
                foreach (preg_split('/[\s,]+/u', mb_strtolower((string) $name)) ?: [] as $w) {
                    if (mb_strlen($w) > 2) {
                        $words[$w] = true;
                    }
                }
            }

            return array_keys($words);
        });
    }
}
