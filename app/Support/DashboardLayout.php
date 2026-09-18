<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * Which dashboard blocks an admin sees, and in what order.
 *
 * Saved per admin in users.dashboard_layout as {"order": [...], "hidden":
 * [...]} — per admin, not store-wide, because the owner (2026-09-18) wants
 * the analytics arranged "as per my needs", and her needs on a phone are not
 * the manager's on a desk. Nothing here is trusted as saved: every key is
 * checked against the registry on the way out, so a block deleted from
 * DashboardBlocks disappears from an old layout rather than 500ing the page,
 * and a block added later takes its default place in an old layout rather
 * than waiting for a reset nobody knows to press.
 */
final class DashboardLayout
{
    /**
     * The resolved layout for an admin: every registered key exactly once in
     * `order`, and the subset of them in `hidden`.
     *
     * With no saved layout the order is the registry's, and the old
     * store-wide ⚙ choice (Setting `dashboard_panels`) still decides what is
     * hidden, so the day this shipped looked exactly like the day before it.
     *
     * @return array{order: string[], hidden: string[]}
     */
    public static function for(?User $user): array
    {
        $saved = $user?->dashboard_layout;

        if (! is_array($saved)) {
            return ['order' => DashboardBlocks::keys(), 'hidden' => self::legacyHidden()];
        }

        return [
            'order' => self::withMissingInPlace(self::known($saved['order'] ?? [])),
            'hidden' => self::known($saved['hidden'] ?? []),
        ];
    }

    /**
     * A layout as it should be stored: known keys only, each once, in the
     * order given. An empty order is stored as such and resolves to the
     * default on the way back out.
     *
     * @return array{order: string[], hidden: string[]}
     */
    public static function normalise(mixed $order, mixed $hidden): array
    {
        return ['order' => self::known($order), 'hidden' => self::known($hidden)];
    }

    /**
     * The old ⚙ form translated for one admin: every block that belonged to a
     * chosen panel is shown, every block that belonged to an unchosen one is
     * hidden, and blocks that never belonged to a panel keep whatever state
     * the admin's layout already gave them.
     *
     * @param  string[]  $chosenPanels
     * @return array{order: string[], hidden: string[]}
     */
    public static function withLegacyPanels(?User $user, array $chosenPanels): array
    {
        $layout = self::for($user);
        $hidden = array_flip($layout['hidden']);

        foreach (DashboardBlocks::all() as $key => $block) {
            if (! isset($block['legacy_panel'])) {
                continue;
            }

            if (in_array($block['legacy_panel'], $chosenPanels, true)) {
                unset($hidden[$key]);
            } else {
                $hidden[$key] = true;
            }
        }

        return ['order' => $layout['order'], 'hidden' => array_keys($hidden)];
    }

    /**
     * The $deep analytics the visible blocks of a layout read. The
     * controller computes these and nothing else.
     *
     * @param  array{order: string[], hidden: string[]}  $layout
     * @return string[]
     */
    public static function needs(array $layout): array
    {
        return DashboardBlocks::needsOf(array_values(array_diff($layout['order'], $layout['hidden'])));
    }

    /**
     * Blocks hidden by the store-wide `dashboard_panels` setting, when that
     * setting was ever saved. Only blocks that belonged to one of the old
     * panels can be hidden this way.
     *
     * @return string[]
     */
    public static function legacyHidden(): array
    {
        $panels = Setting::get('dashboard_panels', null);

        if (! is_array($panels)) {
            return [];
        }

        $hidden = [];

        foreach (DashboardBlocks::all() as $key => $block) {
            if (isset($block['legacy_panel']) && ! in_array($block['legacy_panel'], $panels, true)) {
                $hidden[] = $key;
            }
        }

        return $hidden;
    }

    /**
     * Registered keys only, each once, in the order given.
     *
     * @return string[]
     */
    private static function known(mixed $keys): array
    {
        if (! is_array($keys)) {
            return [];
        }

        $known = DashboardBlocks::keys();
        $out = [];

        foreach ($keys as $key) {
            if (is_string($key) && in_array($key, $known, true) && ! in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Any registered block the saved order does not mention, slotted in
     * straight after the nearest block that precedes it in the registry —
     * or first, if nothing does. Two new neighbours therefore arrive in
     * registry order, and a saved order with nothing left in it becomes the
     * default order.
     *
     * @param  string[]  $order
     * @return string[]
     */
    private static function withMissingInPlace(array $order): array
    {
        $default = DashboardBlocks::keys();

        foreach ($default as $i => $key) {
            if (in_array($key, $order, true)) {
                continue;
            }

            $at = 0;

            for ($j = $i - 1; $j >= 0; $j--) {
                $before = array_search($default[$j], $order, true);

                if ($before !== false) {
                    $at = $before + 1;
                    break;
                }
            }

            array_splice($order, $at, 0, [$key]);
        }

        return $order;
    }
}
