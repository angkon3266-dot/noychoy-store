<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The order of the homepage's sections — the built-in ones listed in
 * config('home.sections') and the owner's custom sections ("blocks"),
 * interleaved however the owner dragged them in Admin → Appearance → Homepage.
 *
 * The stored order is a list of tokens: a built-in key ("featured") or
 * "block:<id>". It is always read through order() so that a section added to
 * the code later, or a custom section saved before ids existed, still lands
 * somewhere sensible instead of vanishing.
 */
class HomeLayout
{
    /** @return array<int,string> built-in section keys, in their default order */
    public static function builtins(): array
    {
        return array_keys(config('home.sections', []));
    }

    /**
     * Give every custom section a stable id. Sections saved before the
     * builder had ids get one derived from their position, which stays the
     * same until the owner next saves (and the saved copy keeps a real id).
     *
     * @param  array<int,array>  $blocks
     * @return array<int,array>
     */
    public static function withIds(array $blocks): array
    {
        return collect($blocks)->values()->map(function ($b, $i) {
            $b['id'] = self::cleanId($b['id'] ?? null) ?? 'b'.$i;

            return $b;
        })->all();
    }

    public static function cleanId($id): ?string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $id);

        return $id !== '' ? Str::limit($id, 32, '') : null;
    }

    /**
     * The full ordered token list for these blocks.
     *
     * @param  array<int,array>  $blocks  blocks that already carry ids
     * @param  array<int,string>|null  $stored  the saved order (defaults to home_content)
     * @return array<int,string>
     */
    public static function order(array $blocks, ?array $stored = null): array
    {
        $stored ??= (array) (home_content('section_order') ?? []);
        $builtins = self::builtins();
        $blockTokens = collect($blocks)->map(fn ($b) => 'block:'.$b['id'])->all();
        $known = array_merge($builtins, $blockTokens);

        $order = collect($stored)
            ->map(fn ($t) => (string) $t)
            ->filter(fn ($t) => in_array($t, $known, true))
            ->unique()->values()->all();

        // A built-in missing from the saved order (new in the code since the
        // owner last saved) goes back in right after the section that comes
        // before it by default — or first, if nothing before it is placed.
        foreach ($builtins as $i => $key) {
            if (in_array($key, $order, true)) {
                continue;
            }
            $at = 0;
            for ($j = $i - 1; $j >= 0; $j--) {
                $pos = array_search($builtins[$j], $order, true);
                if ($pos !== false) {
                    $at = $pos + 1;
                    break;
                }
            }
            array_splice($order, $at, 0, [$key]);
        }

        // Custom sections not yet placed go at the end, as they always did.
        foreach ($blockTokens as $token) {
            if (! in_array($token, $order, true)) {
                $order[] = $token;
            }
        }

        return $order;
    }
}
