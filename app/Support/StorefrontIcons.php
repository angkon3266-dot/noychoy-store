<?php

namespace App\Support;

/**
 * The icon names an admin can pick for trust badges and the feature strip.
 *
 * These fields used to be free-text emoji boxes, which is how a "Fine Jewelry"
 * store ended up signposted with 💵 🚚 ✨. They are now a picker over the
 * storefront's own stroke icon set.
 *
 * The real set lives in `resources/js/Shared/Icons.jsx` — this list must mirror
 * its keys, and `IconSetParityTest` fails the build if the two ever drift.
 */
class StorefrontIcons
{
    /** @return array<int, string> */
    public static function names(): array
    {
        return [
            'menu', 'close', 'chevronDown', 'chevronRight', 'chevronUp', 'search',
            'user', 'userFull', 'cart', 'bell', 'home', 'discover', 'heart', 'bag',
            'trackBox', 'mail', 'truck', 'gift', 'sparkle', 'diamond', 'medal',
            'tag', 'cash', 'calendar', 'pen', 'pin', 'chat', 'check', 'bulb',
            'shieldCheck', 'globe', 'funnel', 'phone', 'zoomIn',
        ];
    }

    /** The handful worth suggesting first for a trust badge or feature strip. */
    public static function suggested(): array
    {
        return ['truck', 'cash', 'shieldCheck', 'gift', 'diamond', 'globe', 'heart', 'sparkle', 'medal', 'check'];
    }
}
