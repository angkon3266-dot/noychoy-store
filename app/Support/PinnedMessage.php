<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The owner's pinned message (owner, 2026-09-19: "any message I can pin
 * sitewide at the top that will float — like stock clearance sale, flat 30%
 * discount on all products"). Appearance → Pinned message.
 *
 * One line in its own colours, inside the sticky header on every storefront
 * page, so it stays on screen while the shopper scrolls — unlike the
 * announcement bar above the header, which scrolls away and rotates several
 * messages. It is only words: an offer it announces still has to be set up
 * in Admin → Offers or Coupons for the checkout to give it.
 *
 * `until` is optional and in the shop's time (Asia/Dhaka). Past it the message
 * stops being sent, and the page also hides it in the browser at that moment,
 * because a page the LiteSpeed cache served from before can outlive the time
 * it was rendered for (the same reason as App\Support\DailyDeals).
 */
final class PinnedMessage
{
    public const DEFAULT_BG = '#b42318';

    public const DEFAULT_COLOR = '#ffffff';

    /** @return array{id:string,text:string,link:?string,linkLabel:?string,bg:string,color:string,until:?string,dismissible:bool}|null */
    public static function current(): ?array
    {
        $text = trim((string) theme('pinned_text'));

        if (! theme('pinned_enabled') || $text === '') {
            return null;
        }

        $until = static::until();

        if ($until && $until->isPast()) {
            return null;
        }

        $link = trim((string) theme('pinned_link')) ?: null;

        return [
            // Changes whenever the message does, so a shopper who closed last
            // week's sale still sees this week's.
            'id' => substr(md5($text.'|'.$link.'|'.$until?->toIso8601String()), 0, 12),
            'text' => $text,
            'link' => $link,
            'linkLabel' => trim((string) theme('pinned_link_label')) ?: null,
            'bg' => static::colour(theme('pinned_bg'), self::DEFAULT_BG),
            'color' => static::colour(theme('pinned_color'), self::DEFAULT_COLOR),
            'until' => $until?->toIso8601String(),
            'dismissible' => (bool) theme('pinned_dismissible'),
        ];
    }

    /** The end time, from the admin's date-and-time field, in the shop's time. */
    public static function until(): ?Carbon
    {
        $raw = trim((string) theme('pinned_until'));

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw, config('store.timezone', 'Asia/Dhaka'));
        } catch (\Throwable) {
            return null; // a malformed date must not take every page down
        }
    }

    /** Only a #rgb / #rrggbb colour reaches a style attribute. */
    protected static function colour(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $value) ? $value : $fallback;
    }
}
